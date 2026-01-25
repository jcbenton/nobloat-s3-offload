(() => {
	const state = {
		isProcessing: false,
		progress: 0,
		processed: 0,
		total: 0,
	};

	const elements = {
		startButton: document.getElementById("bulk-offload-button"),
		cancelButton: document.getElementById("bulk-offload-cancel-button"),
		progressContainer: document.getElementById("progress-container"),
		progressBar: document.getElementById("offload-progress"),
		progressBarContainer: document.querySelector(".progress-bar-container"),
		progressTitle: document.getElementById("progress-title"),
		progressText: document.getElementById("progress-text"),
		processedCount: document.getElementById("processed-count"),
		totalCount: document.getElementById("total-count"),
		messageContainer: document.createElement("div"),
	};

	const init = () => {
		// Guard against missing elements (e.g., when bulk offload section is not shown).
		if (!elements.progressContainer) {
			return;
		}

		elements.messageContainer.id = "nbs3-message-container";
		elements.progressContainer.parentNode.insertBefore(
			elements.messageContainer,
			elements.progressContainer,
		);

		if (elements.startButton) {
			elements.startButton.addEventListener("click", startBulkOffload);
		}

		if (elements.cancelButton) {
			elements.cancelButton.addEventListener("click", cancelBulkOffload);
		}

		if (elements.progressContainer.dataset.status === "processing") {
			if (elements.startButton) {
				elements.startButton.disabled = true;
			}
			elements.progressContainer.style.display = "block";
			checkProgress();
		}
	};

	const showMessage = (message, isError = false) => {
		elements.messageContainer.textContent = message;
		elements.messageContainer.className = isError
			? "error-message"
			: "success-message";
		elements.messageContainer.style.display = "block";
		setTimeout(() => {
			elements.messageContainer.style.display = "none";
		}, 5000);
	};

	const startBulkOffload = async (e) => {
		e.preventDefault();
		elements.startButton.disabled = true;
		elements.progressContainer.style.display = "block";
		elements.progressBarContainer.style.display = "block";
		elements.progressTitle.style.display = "block";

		const formData = new FormData();
		formData.append("action", "nbs3_start_bulk_offload");
		formData.append(
			"bulk_offload_nonce",
			nbs3_ajax_object.bulk_offload_nonce,
		);
		formData.append("batch_size", 50);

		try {
			const response = await fetch(nbs3_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				state.isProcessing = true;
				checkProgress();
			} else {
				showMessage(
					`Failed to start bulk offload process: ${data.data.message}`,
					true,
				);
				elements.startButton.disabled = false;
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage(
				"An error occurred while starting the bulk offload process",
				true,
			);
			elements.startButton.disabled = false;
		}
	};

	const checkProgress = async () => {
		const formData = new FormData();
		formData.append("action", "nbs3_check_bulk_offload_progress");
		formData.append(
			"bulk_offload_nonce",
			nbs3_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(nbs3_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				updateProgressUI(data.data);
			} else {
				showMessage(`Failed to check progress.`, true);
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage("An error occurred while checking the progress", true);
		}
	};

	const updateProgressUI = (progressData) => {
		state.processed = parseInt(progressData.processed);
		state.total = parseInt(progressData.total);
		state.progress =
			state.processed !== 0 && state.total !== 0
				? (state.processed / state.total) * 100
				: 0;
		state.errors = parseInt(progressData.errors);

		requestAnimationFrame(() => {
			elements.progressBar.style.width = `${state.progress}%`;
			elements.progressBar.setAttribute("aria-valuenow", state.progress);
			elements.progressText.textContent = `${Math.round(
				state.progress,
			)}%`;
			elements.processedCount.textContent = state.processed;
			elements.totalCount.textContent = state.total;

			if (state.total === state.processed && state.total !== 0) {
				completeOffload(state.errors);
			} else if (state.total === 0) {
				noFilesToOffload();
			} else {
				setTimeout(checkProgress, 5000);
			}
		});
	};

	const completeOffload = (errors) => {
		elements.progressText.textContent = "Offload complete!";

		if (errors > 0) {
			elements.progressText.textContent = `Offload complete! ${errors} files failed to offload.`;
		}
		if (elements.startButton) {
			elements.startButton.disabled = false;
			elements.startButton.style.display = "none";
		}
		elements.cancelButton.disabled = true;
		elements.progressBarContainer.style.display = "none";
		elements.progressTitle.style.display = "none";
		elements.cancelButton.style.display = "none";
		state.isProcessing = false;
	};

	const noFilesToOffload = () => {
		elements.progressText.textContent = "No files to offload";
		if (elements.startButton) {
			elements.startButton.disabled = false;
		}
		elements.progressContainer.style.display = "none";
		showMessage("No files to offload");
		state.isProcessing = false;
	};

	const cancelBulkOffload = async (e) => {
		e.preventDefault();
		elements.cancelButton.disabled = true;

		const formData = new FormData();
		formData.append("action", "nbs3_cancel_bulk_offload");
		formData.append(
			"bulk_offload_nonce",
			nbs3_ajax_object.bulk_offload_nonce,
		);

		try {
			const response = await fetch(nbs3_ajax_object.ajax_url, {
				method: "POST",
				credentials: "same-origin",
				body: formData,
			});
			const data = await response.json();

			if (data.success) {
				showMessage("Bulk offload process cancelled successfully!");
				if (elements.startButton) {
					elements.startButton.disabled = false;
				}
				state.isProcessing = false;
			} else {
				showMessage(
					`Failed to cancel bulk offload process: ${data.data.message}`,
					true,
				);
				elements.cancelButton.disabled = false;
			}
		} catch (error) {
			console.error("Error:", error);
			showMessage(
				"An error occurred while cancelling the bulk offload process",
				true,
			);
			elements.cancelButton.disabled = false;
		}
	};

	document.addEventListener("DOMContentLoaded", init);

	// Media Sync Buttons Functionality
	document.addEventListener("DOMContentLoaded", initMediaSyncButtons);

	function initMediaSyncButtons() {
		const syncButton = document.getElementById('nbs3-sync-media-now');
		const removeButton = document.getElementById('nbs3-remove-media-s3');
		const invalidateButton = document.getElementById('nbs3-invalidate-media');
		const actionStatus = document.getElementById('nbs3-media-action-status');
		const statusText = document.getElementById('nbs3-media-status-text');

		if (!syncButton && !removeButton && !invalidateButton) {
			return; // Media actions section not present
		}

		// Helper to show action status message
		function showActionStatus(message, isError = false) {
			if (actionStatus) {
				actionStatus.textContent = message;
				actionStatus.style.color = isError ? '#b32d2e' : '#00a32a';
				setTimeout(() => {
					actionStatus.textContent = '';
				}, 5000);
			}
		}

		// Helper to update status display (same format as Bricks)
		function updateMediaStatus(status) {
			if (statusText && status) {
				statusText.textContent = `${status.offloaded} offloaded, ${status.non_offloaded} pending, ${status.total} total`;
			}
		}

		// Helper to set button loading state
		function setButtonLoading(button, isLoading) {
			if (isLoading) {
				button.disabled = true;
				button.classList.add('updating-message');
				button.setAttribute('data-original-text', button.textContent);
				button.textContent = 'Working...';
			} else {
				button.disabled = false;
				button.classList.remove('updating-message');
				const originalText = button.getAttribute('data-original-text');
				if (originalText) {
					button.textContent = originalText;
				}
			}
		}

		// Helper to disable all buttons
		function setAllButtonsDisabled(disabled) {
			if (syncButton) syncButton.disabled = disabled;
			if (removeButton) removeButton.disabled = disabled;
			if (invalidateButton) invalidateButton.disabled = disabled;
		}

		// Sync Now button handler with batch processing
		if (syncButton) {
			syncButton.addEventListener('click', function(e) {
				e.preventDefault();

				setButtonLoading(syncButton, true);
				setAllButtonsDisabled(true);

				let totalUploaded = 0;
				let totalErrors = 0;
				let initialTotal = 0;
				let retryCount = 0;
				const maxRetries = 2;

				function processBatch() {
					const data = new URLSearchParams();
					data.append('action', 'nbs3_sync_media_now');
					data.append('security_nonce', nbs3_ajax_object.media_sync_nonce);

					fetch(nbs3_ajax_object.ajax_url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: data
					})
					.then(response => {
						if (!response.ok) {
							throw new Error(`HTTP ${response.status}: ${response.statusText}`);
						}
						return response.json();
					})
					.then(data => {
						retryCount = 0; // Reset retry count on success
						if (data.success) {
							totalUploaded += data.data.uploaded || 0;
							totalErrors += data.data.errors || 0;

							// Track initial total on first batch
							if (initialTotal === 0 && data.data.status) {
								initialTotal = data.data.status.non_offloaded + totalUploaded;
							}

							// Update status text with progress like "Syncing... 50/650"
							if (data.data.has_more && statusText) {
								const processed = totalUploaded;
								const total = initialTotal || (data.data.remaining + totalUploaded);
								statusText.textContent = `Syncing... ${processed}/${total}`;
							}

							updateMediaStatus(data.data.status);
							showActionStatus(data.data.message);

							if (data.data.has_more) {
								// Small delay between batches to prevent server overload
								setTimeout(processBatch, 500);
							} else {
								setButtonLoading(syncButton, false);
								setAllButtonsDisabled(false);
								let finalMessage = `Sync completed. ${totalUploaded} uploaded.`;
								if (totalErrors > 0) {
									finalMessage += ` ${totalErrors} errors.`;
								}
								showActionStatus(finalMessage);
								updateMediaStatus(data.data.status);
							}
						} else {
							setButtonLoading(syncButton, false);
							setAllButtonsDisabled(false);
							showActionStatus(data.data?.message || 'Sync failed.', true);
						}
					})
					.catch(error => {
						console.error('Media sync error:', error);

						// Retry on network errors
						if (retryCount < maxRetries) {
							retryCount++;
							console.log(`Retrying batch (attempt ${retryCount})...`);
							setTimeout(processBatch, 2000);
							return;
						}

						setButtonLoading(syncButton, false);
						setAllButtonsDisabled(false);
						showActionStatus(`Sync error: ${error.message}`, true);
					});
				}

				// Show initial syncing message
				if (statusText) {
					statusText.textContent = 'Syncing...';
				}

				processBatch();
			});
		}

		// Remove from S3 button handler
		if (removeButton) {
			removeButton.addEventListener('click', function(e) {
				e.preventDefault();

				if (!confirm('Are you sure you want to remove all offloaded media files from S3? This action cannot be undone.')) {
					return;
				}

				setButtonLoading(removeButton, true);
				setAllButtonsDisabled(true);
				showActionStatus('Removing media files from S3...');

				const data = new URLSearchParams();
				data.append('action', 'nbs3_remove_media_from_s3');
				data.append('security_nonce', nbs3_ajax_object.media_remove_nonce);

				fetch(nbs3_ajax_object.ajax_url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: data
				})
				.then(response => response.json())
				.then(data => {
					setButtonLoading(removeButton, false);
					setAllButtonsDisabled(false);

					if (data.success) {
						showActionStatus(data.data.message);
						// Reload page to update stats
						setTimeout(() => location.reload(), 2000);
					} else {
						showActionStatus(data.data?.message || 'Removal failed.', true);
					}
				})
				.catch(error => {
					setButtonLoading(removeButton, false);
					setAllButtonsDisabled(false);
					showActionStatus('An error occurred during removal.', true);
					console.error('Media removal error:', error);
				});
			});
		}

		// Invalidate button handler
		if (invalidateButton) {
			invalidateButton.addEventListener('click', function(e) {
				e.preventDefault();

				if (!confirm('Are you sure you want to invalidate all media offload tracking? This clears local metadata but does NOT delete files from S3.')) {
					return;
				}

				setButtonLoading(invalidateButton, true);
				setAllButtonsDisabled(true);
				showActionStatus('Invalidating...');

				const data = new URLSearchParams();
				data.append('action', 'nbs3_invalidate_media');
				data.append('security_nonce', nbs3_ajax_object.media_invalidate_nonce);

				fetch(nbs3_ajax_object.ajax_url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: data
				})
				.then(response => response.json())
				.then(data => {
					setButtonLoading(invalidateButton, false);
					setAllButtonsDisabled(false);

					if (data.success) {
						showActionStatus(data.data.message);
						// Reload page to update stats
						setTimeout(() => location.reload(), 2000);
					} else {
						showActionStatus(data.data?.message || 'Invalidation failed.', true);
					}
				})
				.catch(error => {
					setButtonLoading(invalidateButton, false);
					setAllButtonsDisabled(false);
					showActionStatus('An error occurred during invalidation.', true);
					console.error('Media invalidation error:', error);
				});
			});
		}
	}
})();
