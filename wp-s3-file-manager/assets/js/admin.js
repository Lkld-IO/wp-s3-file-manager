/**
 * WP S3 File Manager - Admin JavaScript
 *
 * Handles file upload with progress, file listing, deletion, and URL copying.
 */
(function ($) {
	"use strict";

	var WPS3FM = {
		init: function () {
			this.bindEvents();
			this.loadFiles();
		},

		bindEvents: function () {
			$("#wps3fm-select-file").on("click", function () {
				$("#wps3fm-file-input").trigger("click");
			});

			$("#wps3fm-file-input").on("change", function () {
				if (this.files.length > 0) {
					WPS3FM.uploadFile(this.files[0]);
				}
			});

			// Drag and drop support.
			var $uploadArea = $("#wps3fm-upload-area");

			$uploadArea.on("dragover dragenter", function (e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).addClass("wps3fm-drag-over");
			});

			$uploadArea.on("dragleave drop", function (e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).removeClass("wps3fm-drag-over");
			});

			$uploadArea.on("drop", function (e) {
				var files = e.originalEvent.dataTransfer.files;
				if (files.length > 0) {
					WPS3FM.uploadFile(files[0]);
				}
			});

			// Delegate click handlers for file actions.
			$(document).on("click", ".wps3fm-copy-url", function (e) {
				e.preventDefault();
				WPS3FM.copyUrl($(this).data("url"));
			});

			$(document).on("click", ".wps3fm-delete-file", function (e) {
				e.preventDefault();
				var fileId = $(this).data("id");
				if (confirm(wps3fm.strings.confirm_delete)) {
					WPS3FM.deleteFile(fileId);
				}
			});

			$(document).on("change", ".wps3fm-auth-toggle", function () {
				var fileId = $(this).data("id");
				var requiresAuth = $(this).is(":checked") ? 1 : 0;
				WPS3FM.toggleAuth(fileId, requiresAuth);
			});

			// Test connection button.
			$("#wps3fm-test-connection").on("click", function () {
				WPS3FM.testConnection();
			});

			// Manual sync button on settings page.
			$("#wps3fm-manual-sync").on("click", function () {
				WPS3FM.manualSync();
			});

			// Overdue sync button on settings page.
			$("#wps3fm-run-overdue-sync").on("click", function () {
				WPS3FM.overdueSync();
			});

			// Sync files button.
			$("#wps3fm-sync-files").on("click", function () {
				WPS3FM.syncFiles();
			});
		},

		getChunkSize: function () {
			var phpMax = parseInt(wps3fm.max_upload_size, 10) || (2 * 1024 * 1024);
			var safeMax = Math.floor(phpMax * 0.8); // 80% headroom for POST overhead
			var s3MinPart = 5 * 1024 * 1024; // 5MB S3 multipart minimum
			// If PHP can handle >= 5MB, use the PHP-safe max as chunk size.
			// If PHP max is below 5MB, multipart won't work (S3 requires 5MB min parts).
			if (safeMax >= s3MinPart) {
				return safeMax;
			}
			return 0; // signals that multipart is not possible
		},

		uploadFile: function (file) {
			var chunkSize = this.getChunkSize();
			if (chunkSize === 0) {
				alert("Uploads are disabled because the server\u2019s PHP upload limit is too low for the 5 MB S3 multipart minimum. Please upload files directly to your S3 bucket.");
				return;
			}
			if (file.size > chunkSize) {
				if (!confirm(
					"This file (" + WPS3FM.formatSize(file.size) + ") will be uploaded in multiple chunks, which may be slow. " +
					"For better performance, consider uploading the file directly to your S3 bucket and then syncing.\n\n" +
					"Continue with upload?"
				)) {
					$("#wps3fm-file-input").val("");
					return;
				}
				WPS3FM.uploadFileChunked(file);
			} else {
				WPS3FM.uploadFileSingle(file);
			}
		},

		uploadFileSingle: function (file) {
			var formData = new FormData();
			formData.append("action", "wps3fm_upload_file");
			formData.append("nonce", wps3fm.upload_nonce);
			formData.append("file", file);

			var $progress = $("#wps3fm-upload-progress");
			var $fill = $("#wps3fm-progress-fill");
			var $text = $("#wps3fm-progress-text");

			$progress.show();
			$fill.css("width", "0%");
			$text.text(wps3fm.strings.uploading);

			$.ajax({
				url: wps3fm.ajax_url,
				type: "POST",
				data: formData,
				processData: false,
				contentType: false,
				xhr: function () {
					var xhr = new window.XMLHttpRequest();
					xhr.upload.addEventListener("progress", function (e) {
						if (e.lengthComputable) {
							var percent = Math.round((e.loaded / e.total) * 100);
							$fill.css("width", percent + "%");
							$text.text(percent + "% - " + WPS3FM.formatSize(e.loaded) + " / " + WPS3FM.formatSize(e.total));
						}
					});
					return xhr;
				},
				success: function (response) {
					if (response.success) {
						$fill.css("width", "100%");
						$text.text(wps3fm.strings.upload_success);
						WPS3FM.loadFiles();
						// Reset input.
						$("#wps3fm-file-input").val("");
						// Hide progress after a delay.
						setTimeout(function () {
							$progress.fadeOut();
						}, 3000);
					} else {
						$text.text(wps3fm.strings.upload_error + " " + (response.data && response.data.message ? response.data.message : ""));
					}
				},
				error: function () {
					$text.text(wps3fm.strings.upload_error);
				},
			});
		},

		uploadFileChunked: function (file) {
			var chunkSize = WPS3FM.getChunkSize();
			var totalChunks = Math.ceil(file.size / chunkSize);
			var $progress = $("#wps3fm-upload-progress");
			var $fill = $("#wps3fm-progress-fill");
			var $text = $("#wps3fm-progress-text");

			$progress.show();
			$fill.css("width", "0%");
			$text.text(wps3fm.strings.uploading + " Initializing...");

			// Smooth progress tracking — uses requestAnimationFrame to
			// interpolate between real XHR progress events so the bar
			// fills continuously, even during the gaps between chunks.
			var tracker = {
				realBytes: 0,      // bytes confirmed by XHR progress events
				displayBytes: 0,   // bytes currently shown on bar
				speed: 0,          // bytes per ms (rolling average)
				lastRealTime: 0,   // timestamp of last real progress event
				lastRealBytes: 0,  // byte count at last real progress event
				animFrame: null,
				active: false,
			};

			function startProgress() {
				tracker.active = true;
				tracker.lastRealTime = Date.now();
				tracker.animFrame = requestAnimationFrame(renderProgress);
			}

			function stopProgress() {
				tracker.active = false;
				if (tracker.animFrame) {
					cancelAnimationFrame(tracker.animFrame);
					tracker.animFrame = null;
				}
			}

			function renderProgress() {
				if (!tracker.active) return;

				var now = Date.now();
				var elapsed = now - tracker.lastRealTime;

				// Estimate current position using measured speed.
				var estimated = tracker.realBytes + (tracker.speed * elapsed);
				estimated = Math.min(estimated, file.size);
				tracker.displayBytes = Math.max(tracker.displayBytes, estimated);

				var percent = Math.min((tracker.displayBytes / file.size) * 100, 99);
				$fill.css("width", percent.toFixed(1) + "%");
				$text.text(
					Math.round(percent) + "% - " +
					WPS3FM.formatSize(tracker.displayBytes) + " / " + WPS3FM.formatSize(file.size)
				);

				tracker.animFrame = requestAnimationFrame(renderProgress);
			}

			function onRealProgress(chunkStart, loaded) {
				var now = Date.now();
				var currentBytes = chunkStart + loaded;
				var bytesDelta = currentBytes - tracker.lastRealBytes;
				var timeDelta = now - tracker.lastRealTime;

				if (timeDelta > 50 && bytesDelta > 0) {
					var instantSpeed = bytesDelta / timeDelta;
					tracker.speed = tracker.speed > 0
						? tracker.speed * 0.7 + instantSpeed * 0.3
						: instantSpeed;
				}

				tracker.realBytes = currentBytes;
				tracker.lastRealBytes = currentBytes;
				tracker.lastRealTime = now;
			}

			// Step 1: Initiate multipart upload.
			$.post(wps3fm.ajax_url, {
				action: "wps3fm_init_chunked_upload",
				nonce: wps3fm.upload_nonce,
				file_name: file.name,
				file_size: file.size,
				content_type: file.type || "application/octet-stream",
			})
				.done(function (response) {
					if (!response.success) {
						$text.text(wps3fm.strings.upload_error + " " + (response.data && response.data.message ? response.data.message : ""));
						return;
					}

					var uploadId = response.data.upload_id;
					var s3Key = response.data.s3_key;
					var parts = [];
					var currentChunk = 0;

					startProgress();

					// Step 2: Upload chunks sequentially.
					function uploadNextChunk() {
						if (currentChunk >= totalChunks) {
							completeUpload();
							return;
						}

						var start = currentChunk * chunkSize;
						var end = Math.min(start + chunkSize, file.size);
						var chunk = file.slice(start, end);
						var partNumber = currentChunk + 1;

						var formData = new FormData();
						formData.append("action", "wps3fm_upload_chunk");
						formData.append("nonce", wps3fm.upload_nonce);
						formData.append("upload_id", uploadId);
						formData.append("s3_key", s3Key);
						formData.append("part_number", partNumber);
						formData.append("chunk", chunk, file.name);

						$.ajax({
							url: wps3fm.ajax_url,
							type: "POST",
							data: formData,
							processData: false,
							contentType: false,
							xhr: function () {
								var xhr = new window.XMLHttpRequest();
								xhr.upload.addEventListener("progress", function (e) {
									if (e.lengthComputable) {
										onRealProgress(start, e.loaded);
									}
								});
								return xhr;
							},
							success: function (resp) {
								if (resp.success) {
									parts.push({
										part_number: partNumber,
										etag: resp.data.etag,
									});
									currentChunk++;
									uploadNextChunk();
								} else {
									stopProgress();
									$text.text(wps3fm.strings.upload_error + " " + (resp.data && resp.data.message ? resp.data.message : ""));
									abortUpload();
								}
							},
							error: function () {
								stopProgress();
								$text.text(wps3fm.strings.upload_error);
								abortUpload();
							},
						});
					}

					// Step 3: Complete multipart upload.
					function completeUpload() {
						stopProgress();
						$fill.css("width", "100%");
						$text.text(wps3fm.strings.uploading + " Finalizing...");

						$.post(wps3fm.ajax_url, {
							action: "wps3fm_complete_chunked_upload",
							nonce: wps3fm.upload_nonce,
							upload_id: uploadId,
							s3_key: s3Key,
							parts: JSON.stringify(parts),
							file_name: file.name,
							file_size: file.size,
							content_type: file.type || "application/octet-stream",
						})
							.done(function (resp) {
								if (resp.success) {
									$fill.css("width", "100%");
									$text.text(wps3fm.strings.upload_success);
									WPS3FM.loadFiles();
									$("#wps3fm-file-input").val("");
									setTimeout(function () {
										$progress.fadeOut();
									}, 3000);
								} else {
									$text.text(wps3fm.strings.upload_error + " " + (resp.data && resp.data.message ? resp.data.message : ""));
								}
							})
							.fail(function () {
								$text.text(wps3fm.strings.upload_error);
							});
					}

					// Abort on failure to clean up S3 multipart state.
					function abortUpload() {
						$.post(wps3fm.ajax_url, {
							action: "wps3fm_abort_chunked_upload",
							nonce: wps3fm.upload_nonce,
							upload_id: uploadId,
							s3_key: s3Key,
						});
					}

					uploadNextChunk();
				})
				.fail(function () {
					$text.text(wps3fm.strings.upload_error);
				});
		},

		deleteFile: function (fileId) {
			$.post(wps3fm.ajax_url, {
				action: "wps3fm_delete_file",
				nonce: wps3fm.delete_nonce,
				file_id: fileId,
			})
				.done(function (response) {
					if (response.success) {
						WPS3FM.loadFiles();
					} else {
						alert(wps3fm.strings.delete_error + " " + (response.data && response.data.message ? response.data.message : ""));
					}
				})
				.fail(function () {
					alert(wps3fm.strings.delete_error);
				});
		},

		toggleAuth: function (fileId, requiresAuth) {
			$.post(wps3fm.ajax_url, {
				action: "wps3fm_toggle_auth",
				nonce: wps3fm.toggle_auth_nonce,
				file_id: fileId,
				requires_auth: requiresAuth,
			})
				.done(function (response) {
					if (!response.success) {
						alert("Failed to update: " + (response.data && response.data.message ? response.data.message : "Unknown error"));
						WPS3FM.loadFiles();
					}
				})
				.fail(function () {
					alert("Failed to update authentication setting.");
					WPS3FM.loadFiles();
				});
		},

		loadFiles: function () {
			var $tbody = $("#wps3fm-files-body");

			if ($tbody.length === 0) {
				return;
			}

			$.post(wps3fm.ajax_url, {
				action: "wps3fm_list_files",
				nonce: wps3fm.list_nonce,
			})
				.done(function (response) {
					if (response.success && response.data.files) {
						WPS3FM.renderFiles(response.data.files);
					}
				})
				.fail(function () {
					$tbody.html('<tr><td colspan="7">Failed to load files.</td></tr>');
				});
		},

		renderFiles: function (files) {
			var $tbody = $("#wps3fm-files-body");
			$tbody.empty();

			if (files.length === 0) {
				$tbody.html('<tr><td colspan="7">No files uploaded yet.</td></tr>');
				return;
			}

			files.forEach(function (file) {
				var $row = $("<tr></tr>");
				$row.append("<td>" + WPS3FM.escapeHtml(file.file_name) + "</td>");
				$row.append("<td>" + WPS3FM.formatSize(file.file_size) + "</td>");
				$row.append("<td>" + WPS3FM.escapeHtml(file.mime_type) + "</td>");
				$row.append(
					'<td><code class="wps3fm-url-text">' +
					WPS3FM.escapeHtml(file.access_url) +
					'</code> <button class="button button-small wps3fm-copy-url" data-url="' +
					WPS3FM.escapeHtml(file.access_url) +
					'">Copy</button></td>'
				);
				var checked = file.requires_auth ? " checked" : "";
				$row.append(
					'<td><label class="wps3fm-toggle">' +
					'<input type="checkbox" class="wps3fm-auth-toggle" data-id="' + file.id + '"' + checked + '>' +
					'<span class="wps3fm-toggle-slider"></span>' +
					'</label></td>'
				);
				$row.append("<td>" + WPS3FM.escapeHtml(file.uploaded_at) + "</td>");
				$row.append(
					'<td><button class="button button-small button-link-delete wps3fm-delete-file" data-id="' + file.id + '">Delete</button></td>'
				);
				$tbody.append($row);
			});
		},

		copyUrl: function (url) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function () {
					WPS3FM.showNotice(wps3fm.strings.copied);
				});
			} else {
				// Fallback for older browsers.
				var $temp = $('<input type="text">');
				$("body").append($temp);
				$temp.val(url).select();
				document.execCommand("copy");
				$temp.remove();
				WPS3FM.showNotice(wps3fm.strings.copied);
			}
		},

		testConnection: function () {
			var $status = $("#wps3fm-connection-status");
			$status.text("Testing...");

			$.post(wps3fm.ajax_url, {
				action: "wps3fm_test_connection",
				nonce: wps3fm.test_nonce,
			})
				.done(function (response) {
					if (response.success) {
						$status.html('<span style="color:green;">&#10003; Connected successfully</span>');
					} else {
						$status.html(
							'<span style="color:red;">&#10007; ' + (response.data && response.data.message ? response.data.message : "Failed") + "</span>"
						);
					}
				})
				.fail(function () {
					$status.html('<span style="color:red;">&#10007; Connection test failed</span>');
				});
		},

		syncFiles: function () {
			var $button = $("#wps3fm-sync-files");
			var originalText = $button.text();

			$button.text("Syncing...").prop("disabled", true);

			$.post(wps3fm.ajax_url, {
				action: "wps3fm_sync_files",
				nonce: wps3fm.sync_nonce,
			})
				.done(function (response) {
					if (response.success) {
						WPS3FM.showNotice(response.data.message);
						WPS3FM.loadFiles(); // Refresh the file list
					} else {
						WPS3FM.showNotice("Sync failed: " + (response.data && response.data.message ? response.data.message : "Unknown error"));
					}
				})
				.fail(function () {
					WPS3FM.showNotice("Sync failed: Network error");
				})
				.always(function () {
					$button.text(originalText).prop("disabled", false);
				});
		},

		manualSync: function () {
			var $button = $("#wps3fm-manual-sync");
			var $status = $("#wps3fm-sync-status");
			var originalText = $button.text();

			$button.text("Syncing...").prop("disabled", true);
			$status.text("");

			$.post(wps3fm.ajax_url, {
				action: "wps3fm_sync_files",
				nonce: wps3fm.sync_nonce,
			})
				.done(function (response) {
					if (response.success) {
						$status.html('<span style="color:green;">&#10003; ' + response.data.message + '</span>');
					} else {
						$status.html('<span style="color:red;">&#10007; Sync failed: ' + (response.data && response.data.message ? response.data.message : "Unknown error") + '</span>');
					}
				})
				.fail(function () {
					$status.html('<span style="color:red;">&#10007; Sync failed: Network error</span>');
				})
				.always(function () {
					$button.text(originalText).prop("disabled", false);
				});
		},

		overdueSync: function () {
			var $button = $("#wps3fm-run-overdue-sync");
			var $status = $("#wps3fm-overdue-sync-status");
			var originalText = $button.text();

			$button.text("Running...").prop("disabled", true);
			$status.text("");

			// First run sync, then reschedule cron
			$.post(wps3fm.ajax_url, {
				action: "wps3fm_sync_files",
				nonce: wps3fm.sync_nonce,
			})
				.done(function (response) {
					if (response.success) {
						var message = response.data.message;
						if (response.data.cron_rescheduled) {
							message += ' Cron rescheduled.';
						}
						$status.html('<span style="color:green;">&#10003; ' + message + '</span>');
						// Reload page after a short delay to refresh cron status
						setTimeout(function () {
							location.reload();
						}, 2000);
					} else {
						$status.html('<span style="color:red;">&#10007; Sync failed: ' + (response.data && response.data.message ? response.data.message : "Unknown error") + '</span>');
					}
				})
				.fail(function () {
					$status.html('<span style="color:red;">&#10007; Sync failed: Network error</span>');
				})
				.always(function () {
					$button.text(originalText).prop("disabled", false);
				});
		},

		formatSize: function (bytes) {
			if (bytes === 0) return "0 B";
			var k = 1024;
			var sizes = ["B", "KB", "MB", "GB", "TB"];
			var i = Math.floor(Math.log(bytes) / Math.log(k));
			return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
		},

		escapeHtml: function (str) {
			if (!str) return "";
			var div = document.createElement("div");
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		},

		showNotice: function (message) {
			var $notice = $('<div class="notice notice-success is-dismissible wps3fm-notice"><p>' + message + "</p></div>");
			$(".wps3fm-wrap h1").after($notice);
			setTimeout(function () {
				$notice.fadeOut(function () {
					$(this).remove();
				});
			}, 3000);
		},
	};

	$(document).ready(function () {
		WPS3FM.init();
	});
})(jQuery);
