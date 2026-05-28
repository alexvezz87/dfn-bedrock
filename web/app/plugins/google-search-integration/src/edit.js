
/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import { __ } from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';

/**
 * WordPress components
 */
import { 
	PanelBody, 
	TextControl, 
	RangeControl,
	Placeholder
} from '@wordpress/components';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit({ attributes, setAttributes }) {
	const {
		searchPlaceholder,
		buttonText,
		externalLinkText,
		resultsPerPage
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={__('Impostazioni Ricerca', 'google-search-integration-block-wp')}>
					<TextControl
						label={__('Placeholder di ricerca', 'google-search-integration-block-wp')}
						value={searchPlaceholder}
						onChange={(value) => setAttributes({ searchPlaceholder: value })}
					/>
					<TextControl
						label={__('Testo del pulsante ricerca', 'google-search-integration-block-wp')}
						value={buttonText}
						onChange={(value) => setAttributes({ buttonText: value })}
					/>
					<TextControl
						label={__('Testo link esterno', 'google-search-integration-block-wp')}
						value={externalLinkText}
						onChange={(value) => setAttributes({ externalLinkText: value })}
					/>
					<RangeControl
						label={__('Risultati per pagina', 'google-search-integration-block-wp')}
						value={resultsPerPage}
						onChange={(value) => setAttributes({ resultsPerPage: value })}
						min={5}
						max={20}
					/>
				</PanelBody>
			</InspectorControls>
			
			<div {...blockProps}>
				<Placeholder
					icon="search"
					label={__('Google Search Integration', 'google-search-integration-block-wp')}
					instructions={__('Questo blocco mostrerà una barra di ricerca Google con risultati personalizzati nel frontend.', 'google-search-integration-block-wp')}
				>
					<div className="google-search-preview">
						<div className="search-form-preview">
							<input 
								type="text" 
								placeholder={searchPlaceholder}
								disabled
								className="search-input-preview"
							/>
							<button 
								type="button" 
								disabled
								className="search-button-preview"
							>
								{buttonText}
							</button>
						</div>
						<div className="search-results-preview">
							<div className="search-result-item">
								<h3>Esempio di Titolo Risultato</h3>
								<p>Questa è una descrizione di esempio che mostra come appariranno i risultati di ricerca nel layout personalizzato.</p>
								<button className="external-link-preview" disabled>
									{externalLinkText}
								</button>
							</div>
						</div>
					</div>
				</Placeholder>
			</div>
		</>
	);
}
