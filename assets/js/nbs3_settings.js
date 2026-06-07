addEventListener("DOMContentLoaded", function () {
	// Helper function to initialize password toggle functionality
	function initPasswordToggles() {
		const passwordToggles = document.querySelectorAll('.nbs3-toggle-password');
		
		passwordToggles.forEach(function(toggleButton) {
			// Remove any existing listeners by cloning the button
			const newToggleButton = toggleButton.cloneNode(true);
			toggleButton.parentNode.replaceChild(newToggleButton, toggleButton);
			
			newToggleButton.addEventListener('click', function(e) {
				e.preventDefault();
				
				// Find the password input field (sibling of the button)
				const passwordWrapper = newToggleButton.closest('.nbs3-password-field-wrapper');
				const passwordInput = passwordWrapper.querySelector('.nbs3-password-input');
				const icon = newToggleButton.querySelector('.dashicons');
				
				if (passwordInput.type === 'password') {
					passwordInput.type = 'text';
					icon.classList.remove('dashicons-visibility');
					icon.classList.add('dashicons-hidden');
					newToggleButton.setAttribute('aria-label', 'Hide password');
				} else {
					passwordInput.type = 'password';
					icon.classList.remove('dashicons-hidden');
					icon.classList.add('dashicons-visibility');
					newToggleButton.setAttribute('aria-label', 'Show password');
				}
			});
		});
	}

	// Connection status update function
	function updateConnectionStatus(isConnected, lastCheckTime, message = '') {
		// Find or create the status element
		let statusElement = document.querySelector('.nbs3-connection-status');
		const actionsContainer = document.querySelector('.nbs3-credentials-actions');

		if (!statusElement && actionsContainer) {
			// Create new status element
			statusElement = document.createElement('div');
			statusElement.className = 'nbs3-connection-status';
			actionsContainer.parentNode.insertBefore(statusElement, actionsContainer);
		}

		if (!statusElement) return;

		// Update status class
		statusElement.className = `nbs3-connection-status ${isConnected ? 'connected' : 'disconnected'}`;

		// Update icon
		const icon = isConnected ?
			'<span class="dashicons dashicons-yes-alt"></span>' :
			'<span class="dashicons dashicons-warning"></span>';

		// Update status text
		const statusText = message || (isConnected ? 'Connected' : 'Disconnected');

		// Update the entire content safely (XSS protection)
		statusElement.innerHTML = icon; // icon is hardcoded HTML, safe

		const textSpan = document.createElement('span');
		textSpan.className = 'nbs3-status-text';
		textSpan.textContent = statusText; // textContent escapes HTML
		statusElement.appendChild(textSpan);

		const timeSpan = document.createElement('span');
		timeSpan.className = 'nbs3-status-time';
		timeSpan.textContent = 'Last check: ' + lastCheckTime;
		statusElement.appendChild(timeSpan);

		// Only auto-clear success messages, keep error messages visible
		if (message && isConnected) {
			setTimeout(function () {
				const statusTextEl = statusElement.querySelector('.nbs3-status-text');
				if (statusTextEl) {
					statusTextEl.textContent = 'Connected';
				}
			}, 5000);
		}
	}

	// Initialize test connection button
	function initTestConnection() {
		const testButton = document.querySelector(".nbs3_js_test_connection");

		if (!testButton) {
			return;
		}

		// Skip if listener already attached
		if (testButton.hasAttribute('data-listener-attached')) {
			return;
		}

		// Mark as having listener attached
		testButton.setAttribute('data-listener-attached', 'true');

		testButton.addEventListener("click", function (e) {
			e.preventDefault();

			// Add loading state
			testButton.classList.add('loading');
			testButton.disabled = true;

			const data = {
				action: "nbs3_test_connection",
				security_nonce: nbs3_ajax_object.nonce,
			};

			fetch(nbs3_ajax_object.ajax_url, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: new URLSearchParams(data),
			})
				.then((response) => response.json())
				.then((data) => {
					testButton.classList.remove('loading');
					testButton.disabled = false;

					const lastCheckTime = data.data.last_check;
					const message = data.data.message;
					updateConnectionStatus(data.success, lastCheckTime, message);
				})
				.catch((error) => {
					testButton.classList.remove('loading');
					testButton.disabled = false;

					const lastCheckTime = new Date().toLocaleString();
					updateConnectionStatus(false, lastCheckTime, 'Connection failed!');
					console.error("Error:", error.message);
				});
		});
	}

	// Initialize test connection button
	initTestConnection();

	// Enable Path Prefix input if checkbox was enabled
	const pathPrefixCheckbox = document.getElementById("path_prefix_active");
	const pathPrefixInput = document.getElementById("path_prefix");

	if (pathPrefixCheckbox && pathPrefixInput) {
		pathPrefixCheckbox.addEventListener("change", function () {
			pathPrefixInput.disabled = !this.checked;
		});
	}

	// Master enable/disable toggle with auto-save
	const masterEnableCheckbox = document.getElementById("plugin_enabled");
	const masterEnableLabel = document.querySelector(".nbs3-toggle-label");
	const masterEnableField = document.querySelector(".nbs3-master-enable td");
	const setupNotice = document.querySelector(".nbs3-setup-notice");

	if (masterEnableCheckbox && masterEnableLabel) {
		masterEnableCheckbox.addEventListener("change", function () {
			const isEnabled = this.checked;

			// Update toggle label
			masterEnableLabel.textContent = isEnabled ? "Saving..." : "Saving...";

			// Update description text
			if (masterEnableField) {
				const description = masterEnableField.querySelector(".description");
				if (description) {
					description.textContent = "Saving...";
					description.style.color = "#666";
				}
			}

			// Auto-save via AJAX
			const formData = new URLSearchParams();
			formData.append('action', 'nbs3_toggle_plugin_status');
			formData.append('security_nonce', nbs3_ajax_object.toggle_status_nonce);
			formData.append('plugin_enabled', isEnabled ? '1' : '0');

			fetch(nbs3_ajax_object.ajax_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: formData
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					// Update UI to reflect saved state
					masterEnableLabel.textContent = isEnabled ? "Enabled" : "Disabled";

					if (masterEnableField) {
						const description = masterEnableField.querySelector(".description");
						if (description) {
							if (isEnabled) {
								description.textContent = "The plugin is active and will offload media to S3.";
								description.style.color = "#00a32a";
							} else {
								description.textContent = "The plugin is disabled. Media will not be offloaded until you enable it.";
								description.style.color = "";
							}
						}
					}

					// Show/hide setup notice
					if (setupNotice) {
						setupNotice.style.display = isEnabled ? "none" : "block";
					}
				} else {
					// Revert on error
					masterEnableCheckbox.checked = !isEnabled;
					masterEnableLabel.textContent = !isEnabled ? "Enabled" : "Disabled";
					alert(data.data?.message || 'Failed to save plugin status.');
				}
			})
			.catch(error => {
				// Revert on error
				masterEnableCheckbox.checked = !isEnabled;
				masterEnableLabel.textContent = !isEnabled ? "Enabled" : "Disabled";
				console.error('Error saving plugin status:', error);
			});
		});
	}

	// Initialize password toggles (function defined above handles the logic)
	initPasswordToggles();

	// AJAX Settings Save functionality
	const settingsForm = document.querySelector('#nbs3 form');
	
	if (settingsForm) {
		// Get all sections
		const masterEnableSection = document.querySelector('.nbs3-master-enable-section');
		const cloudProviderSection = document.querySelector('.nbs3-cloud-provider-settings');
		const generalSection = document.querySelector('.nbs3-general-settings');
		
		// Function to add overlay to a section
		function addOverlay(section) {
			if (!section) return;
			
			// Check if overlay already exists
			let overlay = section.querySelector('.nbs3-section-overlay');
			if (!overlay) {
				overlay = document.createElement('div');
				overlay.className = 'nbs3-section-overlay';
				section.style.position = 'relative';
				section.appendChild(overlay);
			}
			overlay.classList.add('active');
		}
		
		// Function to remove overlay from a section
		function removeOverlay(section) {
			if (!section) return;
			
			const overlay = section.querySelector('.nbs3-section-overlay');
			if (overlay) {
				overlay.classList.remove('active');
				setTimeout(() => {
					if (!overlay.classList.contains('active')) {
						overlay.remove();
					}
				}, 300);
			}
		}
		
		// Function to set button loading state
		function setButtonLoading(button, isLoading) {
			if (isLoading) {
				button.classList.add('loading');
				button.disabled = true;
				// Store original text and HTML if not already stored
				if (!button.getAttribute('data-original-text')) {
					if (button.tagName === 'INPUT') {
						button.setAttribute('data-original-text', button.value);
					} else {
						button.setAttribute('data-original-text', button.textContent.trim());
						button.setAttribute('data-original-html', button.innerHTML);
					}
				}
				
				// For INPUT elements, we need to wrap them to show the spinner
				// since ::after doesn't work on input elements
				if (button.tagName === 'INPUT' && !button.parentElement.classList.contains('nbs3-button-wrapper')) {
					const wrapper = document.createElement('span');
					wrapper.className = 'nbs3-button-wrapper';
					button.parentNode.insertBefore(wrapper, button);
					wrapper.appendChild(button);
				}
			} else {
				button.classList.remove('loading');
				button.disabled = false;
				
				// Unwrap INPUT elements after loading
				if (button.tagName === 'INPUT' && button.parentElement.classList.contains('nbs3-button-wrapper')) {
					const wrapper = button.parentElement;
					wrapper.parentNode.insertBefore(button, wrapper);
					wrapper.remove();
				}
			}
		}
		
		// Function to show success state on button
		function showButtonSuccess(button) {
			// Ensure button is unwrapped before showing success
			if (button.tagName === 'INPUT' && button.parentElement.classList.contains('nbs3-button-wrapper')) {
				const wrapper = button.parentElement;
				wrapper.parentNode.insertBefore(button, wrapper);
				wrapper.remove();
			}
			
			button.classList.add('success');
			const originalText = button.getAttribute('data-original-text');
			const originalHtml = button.getAttribute('data-original-html');
			
			if (button.tagName === 'INPUT') {
				button.value = '✓ Saved!';
			} else {
				button.innerHTML = '<span class="dashicons dashicons-yes"></span> Saved!';
			}
			
			setTimeout(() => {
				button.classList.remove('success');
				if (button.tagName === 'INPUT') {
					button.value = originalText || 'Save Changes';
				} else {
					// Restore original HTML if available, otherwise reconstruct
					if (originalHtml) {
						button.innerHTML = originalHtml;
					} else {
						button.innerHTML = '<span class="dashicons dashicons-saved"></span> ' + originalText;
					}
				}
			}, 2500);
		}

	// Function to display error message(s)
	function showErrorMessage(messages) {
		// Scroll to top
		window.scrollTo({ top: 0, behavior: 'smooth' });

		// Remove existing error messages
		const existingErrors = document.querySelectorAll('.nbs3-ajax-error');
		existingErrors.forEach(error => error.remove());

		// Handle both string and array inputs
		const messageArray = Array.isArray(messages) ? messages : [messages];

		// Create error message element
		const errorDiv = document.createElement('div');
		errorDiv.className = 'notice notice-error is-dismissible nbs3-ajax-error';

		// Build the content safely using DOM methods (XSS protection)
		if (messageArray.length === 1) {
			const p = document.createElement('p');
			p.textContent = messageArray[0];
			errorDiv.appendChild(p);
		} else {
			const ul = document.createElement('ul');
			ul.style.margin = '0.5em 0';
			ul.style.listStyle = 'disc';
			ul.style.paddingLeft = '20px';

			messageArray.forEach(msg => {
				const li = document.createElement('li');
				li.textContent = msg;
				ul.appendChild(li);
			});

			errorDiv.appendChild(ul);
		}

		// Insert at the top of the form
		const noticeAnchor = document.querySelector('.nbs3-print-notices-after');
		if (noticeAnchor) {
			noticeAnchor.parentNode.insertBefore(errorDiv, noticeAnchor.nextSibling);
		}

		// Make dismissible work
		if (typeof wp !== 'undefined' && wp.notices) {
			wp.notices.init();
		}
	}
		
		// Function to handle form save via AJAX
		function saveSettings(button) {
			// Prevent multiple submissions
			if (button.disabled) return;
			
			// Determine if this is the credentials-only save button
			const isCredentialsButton = button.classList.contains('nbs3-save-credentials');
			
			// Set only the clicked button to loading state
			setButtonLoading(button, true);
			
			// Track when the loading started for minimum duration
			const loadingStartTime = Date.now();
			const minimumLoadingDuration = 800; // 800ms minimum loading time for better UX
			
			// Collect form data
			const formData = new FormData(settingsForm);
			
			// Determine which requests to send and which sections to overlay
			const requests = [];
			
			if (isCredentialsButton) {
				// Only save credentials
				addOverlay(cloudProviderSection);
				
				const credentialsData = new URLSearchParams();
				credentialsData.append('action', 'nbs3_save_credentials');
				credentialsData.append('security_nonce', nbs3_ajax_object.save_credentials_nonce);
				
				// Add all nbs3_credentials fields
				for (let [key, value] of formData.entries()) {
					if (key.startsWith('nbs3_credentials[')) {
						credentialsData.append(key, value);
					}
				}
				
				requests.push(
					fetch(nbs3_ajax_object.ajax_url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: credentialsData
					}).then(response => response.json())
				);
			} else {
				// Save both general settings and credentials
				addOverlay(masterEnableSection);
				addOverlay(cloudProviderSection);
				addOverlay(generalSection);
				
				// Prepare general settings data
				const generalSettingsData = new URLSearchParams();
				generalSettingsData.append('action', 'nbs3_save_general_settings');
				generalSettingsData.append('security_nonce', nbs3_ajax_object.save_general_nonce);
				
				// Add all nbs3_settings fields
				for (let [key, value] of formData.entries()) {
					if (key.startsWith('nbs3_settings[')) {
						generalSettingsData.append(key, value);
					}
				}
				
				// Prepare credentials data
				const credentialsData = new URLSearchParams();
				credentialsData.append('action', 'nbs3_save_credentials');
				credentialsData.append('security_nonce', nbs3_ajax_object.save_credentials_nonce);
				
				// Add all nbs3_credentials fields
				for (let [key, value] of formData.entries()) {
					if (key.startsWith('nbs3_credentials[')) {
						credentialsData.append(key, value);
					}
				}
				
				requests.push(
					fetch(nbs3_ajax_object.ajax_url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: generalSettingsData
					}).then(response => response.json())
				);
				
				requests.push(
					fetch(nbs3_ajax_object.ajax_url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: credentialsData
					}).then(response => response.json())
				);
			}
			
			// Send the appropriate request(s) and ensure minimum loading duration
			Promise.all([
				Promise.all(requests),
				new Promise(resolve => {
					const elapsed = Date.now() - loadingStartTime;
					const remaining = Math.max(0, minimumLoadingDuration - elapsed);
					setTimeout(resolve, remaining);
				})
			])
			.then(([responses]) => {
				// Remove overlays
				if (isCredentialsButton) {
					removeOverlay(cloudProviderSection);
				} else {
					removeOverlay(masterEnableSection);
					removeOverlay(cloudProviderSection);
					removeOverlay(generalSection);
				}

				// Remove loading state from the clicked button
				setButtonLoading(button, false);
				
				// Check if all requests succeeded
				const allSucceeded = responses.every(response => response.success);
				
				if (allSucceeded) {
					// Show success on the clicked button
					showButtonSuccess(button);
				} else {
					// Handle errors
					let errorMessages = [];
					if (isCredentialsButton) {
						// Only credentials response
						if (!responses[0].success) {
							const msg = responses[0].data?.message || 'Failed to save credentials.';
							errorMessages.push(msg);
						}
					} else {
						// Both general and credentials responses
						if (!responses[0].success) {
							const generalError = responses[0].data?.message || 'Failed to save general settings.';
							if (!errorMessages.includes(generalError)) {
								errorMessages.push(generalError);
							}
						}
						if (responses[1] && !responses[1].success) {
							const credError = responses[1].data?.message || 'Failed to save credentials.';
							// Only add if it's different from already collected errors (avoid duplicates)
							if (!errorMessages.includes(credError)) {
								errorMessages.push(credError);
							}
						}
					}
					// Pass array of error messages (will be displayed as list if multiple)
					showErrorMessage(errorMessages);
				}
			})
			.catch(error => {
				// Remove overlays
				if (isCredentialsButton) {
					removeOverlay(cloudProviderSection);
				} else {
					removeOverlay(masterEnableSection);
					removeOverlay(cloudProviderSection);
					removeOverlay(generalSection);
				}

				// Remove loading state from the clicked button
				setButtonLoading(button, false);
				
				// Show error
				showErrorMessage('An error occurred while saving settings. Please try again.');
				console.error('Error:', error);
			});
		}
		
		// Intercept form submission
		settingsForm.addEventListener('submit', function(e) {
			e.preventDefault();

			// Find which button was clicked
			const submitButton = e.submitter || settingsForm.querySelector('input[type="submit"]');
			saveSettings(submitButton);
		});
	}

	// Bricks CSS Sync functionality
	initBricksSyncButtons();
	initBricksThemeAssetsSyncButtons();

	function initBricksSyncButtons() {
		const syncNowButton = document.getElementById('nbs3-sync-bricks-now');
		const removeButton = document.getElementById('nbs3-remove-bricks-s3');
		const invalidateButton = document.getElementById('nbs3-invalidate-bricks-css');
		const statusText = document.getElementById('nbs3-bricks-status-text');
		const actionStatus = document.getElementById('nbs3-bricks-action-status');

		if (!syncNowButton && !removeButton && !invalidateButton) {
			return; // Bricks section not present
		}

		// Helper to update status display
		function updateBricksStatus(status) {
			if (statusText && status) {
				statusText.textContent = `${status.synced} synced, ${status.pending} pending, ${status.total} total`;
			}
		}

		// Helper to show action status message
		function showActionStatus(message, isError = false) {
			if (actionStatus) {
				actionStatus.textContent = message;
				actionStatus.style.color = isError ? '#b32d2e' : '#00a32a';

				// Clear after 5 seconds
				setTimeout(() => {
					actionStatus.textContent = '';
				}, 5000);
			}
		}

		// Helper to set button loading state
		function setBrickButtonLoading(button, isLoading) {
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

		// Sync Now button handler with batch processing
		if (syncNowButton) {
			syncNowButton.addEventListener('click', function(e) {
				e.preventDefault();

				setBrickButtonLoading(syncNowButton, true);
				if (removeButton) removeButton.disabled = true;
				if (invalidateButton) invalidateButton.disabled = true;

				let totalUploaded = 0;
				let totalErrors = 0;

				// Recursive function to process batches
				function processBatch() {
					showActionStatus('Syncing files to S3...');

					const data = new URLSearchParams();
					data.append('action', 'nbs3_sync_bricks_now');
					data.append('security_nonce', nbs3_ajax_object.bricks_sync_nonce);

					fetch(nbs3_ajax_object.ajax_url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: data
					})
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							totalUploaded += data.data.uploaded || 0;
							totalErrors += data.data.errors || 0;
							updateBricksStatus(data.data.status);
							showActionStatus(data.data.message);

							// Continue if there are more files to process
							if (data.data.has_more) {
								processBatch();
							} else {
								// All done
								setBrickButtonLoading(syncNowButton, false);
								if (removeButton) removeButton.disabled = false;
								if (invalidateButton) invalidateButton.disabled = false;
								const finalMessage = `Sync completed. ${totalUploaded} uploaded, ${data.data.deleted || 0} deleted.`;
								showActionStatus(finalMessage);
							}
						} else {
							setBrickButtonLoading(syncNowButton, false);
							if (removeButton) removeButton.disabled = false;
							if (invalidateButton) invalidateButton.disabled = false;
							showActionStatus(data.data?.message || 'Sync failed.', true);
						}
					})
					.catch(error => {
						setBrickButtonLoading(syncNowButton, false);
						if (removeButton) removeButton.disabled = false;
						if (invalidateButton) invalidateButton.disabled = false;
						showActionStatus('An error occurred during sync.', true);
						console.error('Bricks sync error:', error);
					});
				}

				// Start batch processing
				processBatch();
			});
		}

		// Remove from S3 button handler
		if (removeButton) {
			removeButton.addEventListener('click', function(e) {
				e.preventDefault();

				// Confirm action
				if (!confirm('Are you sure you want to remove all Bricks CSS files from S3? This action cannot be undone.')) {
					return;
				}

				setBrickButtonLoading(removeButton, true);
				if (syncNowButton) syncNowButton.disabled = true;
				if (invalidateButton) invalidateButton.disabled = true;
				showActionStatus('Removing files from S3...');

				const data = new URLSearchParams();
				data.append('action', 'nbs3_remove_bricks_from_s3');
				data.append('security_nonce', nbs3_ajax_object.bricks_remove_nonce);

				fetch(nbs3_ajax_object.ajax_url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: data
				})
				.then(response => response.json())
				.then(data => {
					setBrickButtonLoading(removeButton, false);
					if (syncNowButton) syncNowButton.disabled = false;
					if (invalidateButton) invalidateButton.disabled = false;

					if (data.success) {
						showActionStatus(data.data.message);
						updateBricksStatus(data.data.status);
					} else {
						showActionStatus(data.data?.message || 'Removal failed.', true);
					}
				})
				.catch(error => {
					setBrickButtonLoading(removeButton, false);
					if (syncNowButton) syncNowButton.disabled = false;
					if (invalidateButton) invalidateButton.disabled = false;
					showActionStatus('An error occurred during removal.', true);
					console.error('Bricks removal error:', error);
				});
			});
		}

		// Invalidate button handler
		if (invalidateButton) {
			invalidateButton.addEventListener('click', function(e) {
				e.preventDefault();

				// Confirm action
				if (!confirm('Are you sure you want to invalidate all Bricks CSS files? This will attempt to delete from S3 and clear local sync tracking.')) {
					return;
				}

				setBrickButtonLoading(invalidateButton, true);
				if (syncNowButton) syncNowButton.disabled = true;
				if (removeButton) removeButton.disabled = true;
				showActionStatus('Invalidating...');

				const data = new URLSearchParams();
				data.append('action', 'nbs3_invalidate_bricks_css');
				data.append('security_nonce', nbs3_ajax_object.bricks_invalidate_nonce);

				fetch(nbs3_ajax_object.ajax_url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: data
				})
				.then(response => response.json())
				.then(data => {
					setBrickButtonLoading(invalidateButton, false);
					if (syncNowButton) syncNowButton.disabled = false;
					if (removeButton) removeButton.disabled = false;

					if (data.success) {
						showActionStatus(data.data.message);
						updateBricksStatus(data.data.status);
					} else {
						showActionStatus(data.data?.message || 'Invalidation failed.', true);
					}
				})
				.catch(error => {
					setBrickButtonLoading(invalidateButton, false);
					if (syncNowButton) syncNowButton.disabled = false;
					if (removeButton) removeButton.disabled = false;
					showActionStatus('An error occurred during invalidation.', true);
					console.error('Bricks invalidation error:', error);
				});
			});
		}
	}

	// Bricks Theme Assets Sync functionality
	function initBricksThemeAssetsSyncButtons() {
		const syncNowButton = document.getElementById('nbs3-sync-bricks-theme-assets-now');
		const removeButton = document.getElementById('nbs3-remove-bricks-theme-assets-s3');
		const invalidateButton = document.getElementById('nbs3-invalidate-bricks-theme-assets');
		const statusText = document.getElementById('nbs3-bricks-theme-assets-status-text');
		const actionStatus = document.getElementById('nbs3-bricks-theme-assets-action-status');

		if (!syncNowButton && !removeButton && !invalidateButton) {
			return; // Theme assets section not present
		}

		// Helper to update status display
		function updateThemeAssetsStatus(status) {
			if (statusText && status) {
				statusText.textContent = `${status.synced} synced, ${status.pending} pending, ${status.total} total files`;
			}
		}

		// Helper to show action status message
		function showActionStatus(message, isError = false) {
			if (actionStatus) {
				actionStatus.textContent = message;
				actionStatus.style.color = isError ? '#b32d2e' : '#00a32a';

				// Clear after 5 seconds
				setTimeout(() => {
					actionStatus.textContent = '';
				}, 5000);
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

		// Sync Now button handler with batch processing
		if (syncNowButton) {
			syncNowButton.addEventListener('click', function(e) {
				e.preventDefault();

				setButtonLoading(syncNowButton, true);
				if (removeButton) removeButton.disabled = true;
				if (invalidateButton) invalidateButton.disabled = true;

				let totalUploaded = 0;
				let totalErrors = 0;

				// Recursive function to process batches
				function processBatch() {
					showActionStatus('Syncing theme assets to S3...');

					const data = new URLSearchParams();
					data.append('action', 'nbs3_sync_bricks_theme_assets');
					data.append('security_nonce', nbs3_ajax_object.bricks_theme_assets_sync_nonce);

					fetch(nbs3_ajax_object.ajax_url, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: data
					})
					.then(response => response.json())
					.then(data => {
						if (data.success) {
							totalUploaded += data.data.uploaded || 0;
							totalErrors += data.data.errors || 0;
							updateThemeAssetsStatus(data.data.status);
							showActionStatus(data.data.message);

							// Continue if there are more files to process
							if (data.data.has_more) {
								processBatch();
							} else {
								// All done
								setButtonLoading(syncNowButton, false);
								if (removeButton) removeButton.disabled = false;
								if (invalidateButton) invalidateButton.disabled = false;
								const finalMessage = `Sync completed. ${totalUploaded} uploaded, ${data.data.deleted || 0} deleted.`;
								showActionStatus(finalMessage);
							}
						} else {
							setButtonLoading(syncNowButton, false);
							if (removeButton) removeButton.disabled = false;
							if (invalidateButton) invalidateButton.disabled = false;
							showActionStatus(data.data?.message || 'Sync failed.', true);
						}
					})
					.catch(error => {
						setButtonLoading(syncNowButton, false);
						if (removeButton) removeButton.disabled = false;
						if (invalidateButton) invalidateButton.disabled = false;
						showActionStatus('An error occurred during sync.', true);
						console.error('Theme assets sync error:', error);
					});
				}

				// Start batch processing
				processBatch();
			});
		}

		// Remove from S3 button handler
		if (removeButton) {
			removeButton.addEventListener('click', function(e) {
				e.preventDefault();

				// Confirm action
				if (!confirm('Are you sure you want to remove all Bricks theme assets from S3? This action cannot be undone.')) {
					return;
				}

				setButtonLoading(removeButton, true);
				if (syncNowButton) syncNowButton.disabled = true;
				if (invalidateButton) invalidateButton.disabled = true;
				showActionStatus('Removing theme assets from S3...');

				const data = new URLSearchParams();
				data.append('action', 'nbs3_remove_bricks_theme_assets');
				data.append('security_nonce', nbs3_ajax_object.bricks_theme_assets_remove_nonce);

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
					if (syncNowButton) syncNowButton.disabled = false;
					if (invalidateButton) invalidateButton.disabled = false;

					if (data.success) {
						showActionStatus(data.data.message);
						updateThemeAssetsStatus(data.data.status);
					} else {
						showActionStatus(data.data?.message || 'Removal failed.', true);
					}
				})
				.catch(error => {
					setButtonLoading(removeButton, false);
					if (syncNowButton) syncNowButton.disabled = false;
					if (invalidateButton) invalidateButton.disabled = false;
					showActionStatus('An error occurred during removal.', true);
					console.error('Theme assets removal error:', error);
				});
			});
		}

		// Invalidate button handler
		if (invalidateButton) {
			invalidateButton.addEventListener('click', function(e) {
				e.preventDefault();

				// Confirm action
				if (!confirm('Are you sure you want to invalidate all Bricks theme assets? This will attempt to delete from S3 and clear local sync tracking.')) {
					return;
				}

				setButtonLoading(invalidateButton, true);
				if (syncNowButton) syncNowButton.disabled = true;
				if (removeButton) removeButton.disabled = true;
				showActionStatus('Invalidating...');

				const data = new URLSearchParams();
				data.append('action', 'nbs3_invalidate_bricks_theme_assets');
				data.append('security_nonce', nbs3_ajax_object.bricks_invalidate_nonce);

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
					if (syncNowButton) syncNowButton.disabled = false;
					if (removeButton) removeButton.disabled = false;

					if (data.success) {
						showActionStatus(data.data.message);
						updateThemeAssetsStatus(data.data.status);
					} else {
						showActionStatus(data.data?.message || 'Invalidation failed.', true);
					}
				})
				.catch(error => {
					setButtonLoading(invalidateButton, false);
					if (syncNowButton) syncNowButton.disabled = false;
					if (removeButton) removeButton.disabled = false;
					showActionStatus('An error occurred during invalidation.', true);
					console.error('Theme assets invalidation error:', error);
				});
			});
		}
	}
});
