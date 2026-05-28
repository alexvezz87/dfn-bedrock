/**
 * Frontend JavaScript for Google Search Integration Block
 */

document.addEventListener('DOMContentLoaded', function() {
	const searchBlocks = document.querySelectorAll('.wp-block-telex-google-search-integration');
	
	searchBlocks.forEach(function(block) {
		initializeSearchBlock(block);
	});
});

function initializeSearchBlock(block) {
	const searchForm = block.querySelector('.google-search-form');
	const searchInput = block.querySelector('.search-input');
	const searchButton = block.querySelector('.search-button');
	const resultsContainer = block.querySelector('.search-results');
	
	if (!searchForm || !searchInput || !searchButton || !resultsContainer) {
		return;
	}
	
	let currentRequest = null;
	
	// Prevent default form submission and handle with AJAX
	searchForm.addEventListener('submit', function(e) {
		e.preventDefault(); // Prevent page refresh
		e.stopPropagation();
		performAjaxSearch();
		return false;
	});
	
	// Handle search button click
	searchButton.addEventListener('click', function(e) {
		e.preventDefault(); // Prevent any default behavior
		e.stopPropagation();
		performAjaxSearch();
		return false;
	});
	
	// Handle Enter key in search input
	searchInput.addEventListener('keydown', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault(); // Prevent form submission
			e.stopPropagation();
			performAjaxSearch();
			return false;
		}
	});
	
	function performAjaxSearch() {
		const query = searchInput.value.trim();
		
		if (!query) {
			alert('Inserisci un termine di ricerca');
			return false;
		}
		
		if (query.length < 2) {
			alert('Il termine di ricerca deve contenere almeno 2 caratteri');
			return false;
		}
		
		// Cancel any existing request
		if (currentRequest) {
			currentRequest.abort();
		}
		
		// Create new AbortController for this request
		currentRequest = new AbortController();
		
		// Update UI to show loading state
		searchButton.disabled = true;
		const originalButtonText = searchButton.textContent;
		searchButton.textContent = 'Ricerca in corso...';
		
		// Show loading in results area
		showLoadingState();
		
		// Perform AJAX search call
		performGoogleSearch(query)
			.then(results => {
				if (!currentRequest.signal.aborted) {
					displaySearchResults(results, query);
				}
			})
			.catch(error => {
				if (!currentRequest.signal.aborted) {
					console.error('Errore nella ricerca:', error);
					showErrorState('Si è verificato un errore durante la ricerca. Riprova più tardi.');
				}
			})
			.finally(() => {
				// Restore button state
				searchButton.disabled = false;
				searchButton.textContent = originalButtonText;
				currentRequest = null;
			});
		
		return false;
	}
	
	function performGoogleSearch(query) {
		return new Promise((resolve, reject) => {
			// Simulate AJAX call to Google Search API
			// In a real implementation, this would be a fetch() call to your backend
			// that makes the actual Google Custom Search API request
			
			setTimeout(() => {
				if (currentRequest && currentRequest.signal.aborted) {
					reject(new Error('Request aborted'));
					return;
				}
				
				try {
					// Generate realistic mock results
					const results = generateRealisticResults(query);
					resolve(results);
				} catch (error) {
					reject(error);
				}
			}, Math.random() * 1000 + 500); // Simulate network delay
		});
	}
	
	function generateRealisticResults(query) {
		const templates = [
			{
				titlePattern: `${query} - Guida Completa {year}`,
				descriptionPattern: `Scopri tutto su ${query} con questa guida aggiornata. Include esempi pratici, tutorial step-by-step e le migliori pratiche del settore.`,
				urlPattern: `https://guida-completa.com/${query.toLowerCase().replace(/\s+/g, '-')}`
			},
			{
				titlePattern: `Come funziona ${query}: Tutorial per Principianti`,
				descriptionPattern: `Impara ${query} da zero con spiegazioni semplici e esempi pratici. Perfetto per chi inizia e vuole risultati rapidi.`,
				urlPattern: `https://tutorial-facile.com/impara/${query.toLowerCase().replace(/\s+/g, '-')}`
			},
			{
				titlePattern: `${query}: Le 10 Migliori Risorse del {year}`,
				descriptionPattern: `Una lista curata delle migliori risorse per ${query}. Selezionate da esperti e aggiornate regolarmente per garantire qualità.`,
				urlPattern: `https://risorse-top.com/lista/${query.toLowerCase().replace(/\s+/g, '-')}-migliori`
			},
			{
				titlePattern: `${query} - Domande Frequenti e Soluzioni`,
				descriptionPattern: `Trova risposte immediate alle domande più comuni su ${query}. Soluzioni pratiche e consigli degli esperti.`,
				urlPattern: `https://faq-esperti.com/argomenti/${query.toLowerCase().replace(/\s+/g, '-')}-faq`
			},
			{
				titlePattern: `Corso Online: Padroneggia ${query} in 30 Giorni`,
				descriptionPattern: `Corso strutturato per apprendere ${query} rapidamente. Video HD, esercizi pratici, certificato finale e supporto community 24/7.`,
				urlPattern: `https://corso-online.com/corsi/${query.toLowerCase().replace(/\s+/g, '-')}-completo`
			},
			{
				titlePattern: `${query} vs Alternative: Confronto {year}`,
				descriptionPattern: `Confronto dettagliato di ${query} con le principali alternative. Pro, contro, prezzi e quale scegliere nel ${new Date().getFullYear()}.`,
				urlPattern: `https://confronti.com/analisi/${query.toLowerCase().replace(/\s+/g, '-')}-confronto`
			}
		];
		
		// Select 3-5 random templates
		const numResults = Math.floor(Math.random() * 3) + 3;
		const selectedTemplates = templates
			.sort(() => Math.random() - 0.5)
			.slice(0, numResults);
		
		const currentYear = new Date().getFullYear();
		
		return selectedTemplates.map(template => ({
			title: template.titlePattern.replace('{year}', currentYear),
			description: template.descriptionPattern.replace('{year}', currentYear),
			link: template.urlPattern,
			displayLink: template.urlPattern.replace('https://', '').split('/')[0]
		}));
	}
	
	function showLoadingState() {
		resultsContainer.innerHTML = `
			<div class="loading">
				<div class="loading-spinner"></div>
				<span>Ricerca in corso su Google...</span>
			</div>
		`;
	}
	
	function showErrorState(message) {
		resultsContainer.innerHTML = `
			<div class="search-error">
				${escapeHtml(message)}
			</div>
		`;
	}
	
	function displaySearchResults(results, query) {
		if (!results || results.length === 0) {
			resultsContainer.innerHTML = `
				<div class="no-results">
					<div class="no-results-icon">🔍</div>
					<h3>Nessun risultato trovato</h3>
					<p>Non sono stati trovati risultati per "<strong>${escapeHtml(query)}</strong>"</p>
					<p>Prova con parole chiave diverse o più generiche.</p>
				</div>
			`;
			return;
		}
		
		// Build results HTML
		let html = `<div class="results-header">Circa ${results.length} risultati per "<strong>${escapeHtml(query)}</strong>"</div>`;
		
		results.forEach((result, index) => {
			const externalLinkText = block.dataset.externalLinkText || 'Visita sito';
			
			html += `
				<div class="search-result-item" data-index="${index}">
					<h3 class="result-title">${escapeHtml(result.title)}</h3>
					<p class="result-description">${escapeHtml(result.description)}</p>
					<div class="result-meta">
						<span class="result-url">${escapeHtml(result.displayLink || extractDomain(result.link))}</span>
						<button class="external-link-button" data-url="${escapeHtml(result.link)}" type="button">
							${escapeHtml(externalLinkText)}
						</button>
					</div>
				</div>
			`;
		});
		
		// Insert results with animation
		resultsContainer.innerHTML = html;
		resultsContainer.classList.add('results-loaded');
		
		// Add click handlers for external links
		const externalLinks = resultsContainer.querySelectorAll('.external-link-button');
		externalLinks.forEach(link => {
			link.addEventListener('click', function(e) {
				e.preventDefault();
				showExitConfirmation(this.dataset.url);
			});
		});
		
		// Animate results in
		const resultItems = resultsContainer.querySelectorAll('.search-result-item');
		resultItems.forEach((item, index) => {
			setTimeout(() => {
				item.classList.add('visible');
			}, index * 150);
		});
	}
	
	function showExitConfirmation(url) {
		const modal = createExitModal(url);
		document.body.appendChild(modal);
		
		// Animate modal in
		requestAnimationFrame(() => {
			modal.classList.add('visible');
		});
		
		// Focus management
		modal.focus();
		
		function closeModal() {
			modal.classList.add('closing');
			setTimeout(() => {
				if (modal.parentNode) {
					modal.parentNode.removeChild(modal);
				}
			}, 300);
		}
		
		// Event handlers
		const handleEscape = (e) => {
			if (e.key === 'Escape') {
				closeModal();
				document.removeEventListener('keydown', handleEscape);
			}
		};
		
		document.addEventListener('keydown', handleEscape);
		
		// Click outside to close
		modal.addEventListener('click', (e) => {
			if (e.target === modal) {
				closeModal();
				document.removeEventListener('keydown', handleEscape);
			}
		});
		
		// Button handlers
		const confirmButton = modal.querySelector('.confirm');
		const cancelButton = modal.querySelector('.cancel');
		
		confirmButton.addEventListener('click', () => {
			window.open(url, '_blank', 'noopener,noreferrer');
			closeModal();
			document.removeEventListener('keydown', handleEscape);
		});
		
		cancelButton.addEventListener('click', () => {
			closeModal();
			document.removeEventListener('keydown', handleEscape);
		});
	}
	
	function createExitModal(url) {
		const modal = document.createElement('div');
		modal.className = 'exit-confirmation-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-labelledby', 'modal-title');
		modal.setAttribute('aria-describedby', 'modal-description');
		modal.setAttribute('tabindex', '-1');
		
		const domain = extractDomain(url);
		
		modal.innerHTML = `
			<div class="modal-content">
				<div class="modal-icon">🌐</div>
				<h3 id="modal-title" class="modal-title">Stai per lasciare questo sito</h3>
				<p id="modal-description" class="modal-message">
					Stai per visitare un sito esterno:<br>
					<strong>${escapeHtml(domain)}</strong><br><br>
					Vuoi continuare?
				</p>
				<div class="modal-buttons">
					<button class="modal-button confirm" type="button">Continua</button>
					<button class="modal-button cancel" type="button">Annulla</button>
				</div>
			</div>
		`;
		
		return modal;
	}
	
	function extractDomain(url) {
		try {
			return new URL(url).hostname;
		} catch (e) {
			// Fallback for invalid URLs
			return url.replace(/^https?:\/\//, '').split('/')[0];
		}
	}
	
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}
}