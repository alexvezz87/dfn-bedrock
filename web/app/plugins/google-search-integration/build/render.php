<?php
/**
 * @see https://github.com/WordPress/gutenberg/blob/trunk/docs/reference-guides/block-api/block-metadata.md#render
 */

$search_placeholder = isset($attributes['searchPlaceholder']) ? esc_attr($attributes['searchPlaceholder']) : 'Cerca su Google...';
$button_text = isset($attributes['buttonText']) ? esc_html($attributes['buttonText']) : 'Cerca';
$external_link_text = isset($attributes['externalLinkText']) ? esc_attr($attributes['externalLinkText']) : 'Visita sito';
$results_per_page = isset($attributes['resultsPerPage']) ? intval($attributes['resultsPerPage']) : 10;

$wrapper_attributes = get_block_wrapper_attributes([
	'data-external-link-text' => $external_link_text,
	'data-results-per-page' => $results_per_page,
	'data-button-text' => $button_text
]);
?>

<div <?php echo $wrapper_attributes; ?>>
	<form class="google-search-form" role="search" autocomplete="off">
		<input 
			type="text" 
			class="search-input"
			placeholder="<?php echo $search_placeholder; ?>"
			aria-label="<?php esc_attr_e('Campo di ricerca Google', 'google-search-integration-block-wp'); ?>"
			autocomplete="off"
			spellcheck="false"
			required
		>
		<button 
			type="submit" 
			class="search-button"
			aria-label="<?php esc_attr_e('Avvia ricerca Google', 'google-search-integration-block-wp'); ?>"
		>
			<?php echo $button_text; ?>
		</button>
	</form>
	
	<div class="search-results" role="region" aria-live="polite" aria-label="<?php esc_attr_e('Risultati di ricerca Google', 'google-search-integration-block-wp'); ?>">
		<!-- I risultati verranno caricati qui tramite JavaScript in modo asincrono -->
	</div>
</div>