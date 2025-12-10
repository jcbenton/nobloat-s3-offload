addEventListener("DOMContentLoaded", function () {
	// Select the cloud provider dropdown
	const cloudProviderSelect = document.querySelector(
		'select[name="nbs3_settings[cloud_provider]"]',
	);

	if (!cloudProviderSelect) {
		console.error("Cloud provider select not found");
		return;
	}

	// Select the form (assuming it's the parent form of the select field)
	const form = cloudProviderSelect.closest("form");

	if (!form) {
		console.error("Parent form not found");
		return;
	}

	// Add event listener to the select field
	cloudProviderSelect.addEventListener("change", function (e) {
		const selectedProvider = e.target.value;
		
		// Don't do anything if empty/placeholder is selected
		if (!selectedProvider) {
			return;
		}

		// Find the credentials field container
		const credentialsField = document.querySelector('.nbs3-cloud-provider-credentials');
		const cloudProviderSection = document.querySelector('.nbs3-cloud-provider-settings');
		
		if (!credentialsField) {
			console.error('Credentials field container not found');
			return;
		}

		// Add loading overlay
		if (cloudProviderSection) {
			addOverlay(cloudProviderSection);
		}

		// Prepare AJAX request
		const data = new URLSearchParams();
		data.append('action', 'nbs3_get_provider_credentials');
		data.append('security_nonce', nbs3_ajax_object.get_provider_credentials_nonce);
		data.append('provider', selectedProvider);

		// Fetch the credentials HTML
		fetch(nbs3_ajax_object.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: data
		})
		.then(response => response.json())
		.then(data => {
			// Remove loading overlay
			if (cloudProviderSection) {
				removeOverlay(cloudProviderSection);
			}

			if (data.success && data.data.html) {
				// Replace the credentials field content
				const fieldContent = credentialsField.querySelector('td');
				if (fieldContent) {
					fieldContent.innerHTML = data.data.html;
					
					// Re-initialize password toggle listeners
					initPasswordToggles();
					
					// Re-initialize test connection button
					initTestConnection();

					// Auto-save the provider selection
					saveProviderSelection(selectedProvider);
				}
			} else {
				// Show error message
				const errorMessage = data.data?.message || 'Failed to load credentials fields.';
				alert(errorMessage);
				console.error('Error loading credentials:', errorMessage);
			}
		})
		.catch(error => {
			// Remove loading overlay
			if (cloudProviderSection) {
				removeOverlay(cloudProviderSection);
			}
			
			alert('An error occurred while loading credentials fields. Please try again.');
			console.error('Error:', error);
		});
	});
	
	// Helper function to save provider selection
	function saveProviderSelection(provider) {
		const data = new URLSearchParams();
		data.append('action', 'nbs3_save_general_settings');
		data.append('security_nonce', nbs3_ajax_object.save_general_nonce);
		data.append('nbs3_settings[cloud_provider]', provider);

		// Get other existing settings to preserve them
		const autoOffload = document.getElementById('auto_offload_uploads');
		const retentionPolicyRadios = document.querySelectorAll('input[name="nbs3_settings[retention_policy]"]');
		const objectVersioning = document.getElementById('object_versioning');
		const pathPrefixActive = document.getElementById('path_prefix_active');
		const pathPrefix = document.getElementById('path_prefix');
		const mirrorDelete = document.getElementById('mirror_delete');

		if (autoOffload) {
			data.append('nbs3_settings[auto_offload_uploads]', autoOffload.checked ? '1' : '0');
		}

		retentionPolicyRadios.forEach(radio => {
			if (radio.checked) {
				data.append('nbs3_settings[retention_policy]', radio.value);
			}
		});

		if (objectVersioning) {
			data.append('nbs3_settings[object_versioning]', objectVersioning.checked ? '1' : '0');
		}

		if (pathPrefixActive) {
			data.append('nbs3_settings[path_prefix_active]', pathPrefixActive.checked ? '1' : '0');
		}

		if (pathPrefix) {
			data.append('nbs3_settings[path_prefix]', pathPrefix.value);
		}

		if (mirrorDelete) {
			data.append('nbs3_settings[mirror_delete]', mirrorDelete.checked ? '1' : '0');
		}

		// Save in the background
		fetch(nbs3_ajax_object.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: data
		})
		.then(response => response.json())
		.catch(() => {
			// Silent fail for background save
		});
	}

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

	// Helper function to initialize test connection button
	function initTestConnection() {
		const nbs3_test_connection = document.querySelector(".nbs3_js_test_connection");

		if (nbs3_test_connection) {
			// Skip if listener already attached
			if (nbs3_test_connection.hasAttribute('data-listener-attached')) {
				return;
			}

			// Remove any existing listeners by cloning the button
			const newTestButton = nbs3_test_connection.cloneNode(true);
			nbs3_test_connection.parentNode.replaceChild(newTestButton, nbs3_test_connection);

			// Mark as having listener attached
			newTestButton.setAttribute('data-listener-attached', 'true');

			newTestButton.addEventListener("click", function (e) {
				e.preventDefault();

				// Add loading state
				newTestButton.classList.add('loading');
				newTestButton.disabled = true;

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
						newTestButton.classList.remove('loading');
						newTestButton.disabled = false;

						let lastCheckTime = data.data.last_check;
						let message = data.data.message;
						updateConnectionStatus(data.success, lastCheckTime, message);
					})
					.catch((error) => {
						newTestButton.classList.remove('loading');
						newTestButton.disabled = false;

						const lastCheckTime = new Date().toLocaleString();
						updateConnectionStatus(false, lastCheckTime, 'Connection failed!');
						console.error("Error:", error.message);
					});
			});
		}
	}

	// Connection test functionality
	const nbs3_test_connection = document.querySelector(".nbs3_js_test_connection");

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
		
		// Update the entire content
		statusElement.innerHTML = `
			${icon}
			<span class="nbs3-status-text">${statusText}</span>
			<span class="nbs3-status-time">Last check: ${lastCheckTime}</span>
		`;

		// Show a temporary success message
		if (message) {
			setTimeout(function () {
				const statusTextEl = statusElement.querySelector('.nbs3-status-text');
				if (statusTextEl) {
					statusTextEl.textContent = isConnected ? 'Connected' : 'Disconnected';
				}
			}, 3000);
		}
	}

	// Initialize test connection button (if not already done by initTestConnection)
	if (nbs3_test_connection && !nbs3_test_connection.hasAttribute('data-listener-attached')) {
		initTestConnection();
	}

	// Enable Path Prefix input if checkbox was enabled
	const pathPrefixCheckbox = document.getElementById("path_prefix_active");
	const pathPrefixInput = document.getElementById("path_prefix");

	if (pathPrefixCheckbox && pathPrefixInput) {
		pathPrefixCheckbox.addEventListener("change", function () {
			pathPrefixInput.disabled = !this.checked;
		});
	}

	// Initialize password toggles (function defined above handles the logic)
	initPasswordToggles();

	// AJAX Settings Save functionality
	const settingsForm = document.querySelector('#nbs3 form');
	
	if (settingsForm) {
		// Get both sections
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

	function initBricksSyncButtons() {
		const syncNowButton = document.getElementById('nbs3-sync-bricks-now');
		const removeButton = document.getElementById('nbs3-remove-bricks-s3');
		const statusText = document.getElementById('nbs3-bricks-status-text');
		const actionStatus = document.getElementById('nbs3-bricks-action-status');

		if (!syncNowButton && !removeButton) {
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

		// Sync Now button handler
		if (syncNowButton) {
			syncNowButton.addEventListener('click', function(e) {
				e.preventDefault();

				setBrickButtonLoading(syncNowButton, true);
				if (removeButton) removeButton.disabled = true;
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
					setBrickButtonLoading(syncNowButton, false);
					if (removeButton) removeButton.disabled = false;

					if (data.success) {
						showActionStatus(data.data.message);
						updateBricksStatus(data.data.status);
					} else {
						showActionStatus(data.data?.message || 'Sync failed.', true);
					}
				})
				.catch(error => {
					setBrickButtonLoading(syncNowButton, false);
					if (removeButton) removeButton.disabled = false;
					showActionStatus('An error occurred during sync.', true);
					console.error('Bricks sync error:', error);
				});
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
					showActionStatus('An error occurred during removal.', true);
					console.error('Bricks removal error:', error);
				});
			});
		}
	}
});
