import { useState, useEffect } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api/client';
import CopyButton from './CopyButton';

function SanitizerTester() {
	const [markdown, setMarkdown] = useState('');
	const [content, setContent] = useState('');
	const [template, setTemplate] = useState('');
	const [templates, setTemplates] = useState([]);
	const [selectedTemplateId, setSelectedTemplateId] = useState('');
	const [loadingTemplate, setLoadingTemplate] = useState(false);
	const [result, setResult] = useState(null);
	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState(null);

	// Fetch templates on mount
	useEffect(() => {
		const fetchTemplates = async () => {
			try {
				const response = await api.getTemplates();
				setTemplates(response.data || []);
			} catch (err) {
				// Silently fail - templates are optional
			}
		};
		fetchTemplates();
	}, []);

	// Load template content when selection changes
	const handleTemplateSelect = async (templateId) => {
		setSelectedTemplateId(templateId);

		if (!templateId) {
			return;
		}

		setLoadingTemplate(true);
		try {
			const response = await api.getTemplate(templateId);
			const templateContent = response.data?.content || response.data?.neutralized_content || '';
			setTemplate(templateContent);
		} catch (err) {
			setError(__('Erreur lors du chargement du template.', 'ai-forge-devtools'));
		} finally {
			setLoadingTemplate(false);
		}
	};

	const handleSubmit = async () => {
		if (!content.trim()) {
			setError(__('Le contenu Gutenberg est requis.', 'ai-forge-devtools'));
			return;
		}

		if (!markdown.trim()) {
			setError(__('Le contenu Markdown est requis.', 'ai-forge-devtools'));
			return;
		}

		setIsLoading(true);
		setError(null);
		setResult(null);

		try {
			const response = await api.testSanitizer(markdown, content, template);
			setResult(response.data);
		} catch (err) {
			setError(err.message || __('Une erreur est survenue.', 'ai-forge-devtools'));
		} finally {
			setIsLoading(false);
		}
	};

	const handleClear = () => {
		setMarkdown('');
		setContent('');
		setTemplate('');
		setSelectedTemplateId('');
		setResult(null);
		setError(null);
	};

	return (
		<div className="sanitizer-tester">
			<div className="sanitizer-tester__section-title">
				<span className="dashicons dashicons-admin-tools"></span>
				{__('Test du GutenbergSanitizer', 'ai-forge-devtools')}
			</div>

			{error && (
				<Notice status="error" isDismissible onDismiss={() => setError(null)}>
					{error}
				</Notice>
			)}

			<div className="sanitizer-tester__inputs">
				<div className="sanitizer-tester__panel">
					<div className="sanitizer-tester__panel-header">
						<span className="dashicons dashicons-editor-code"></span>
						<span>{__('Source Markdown', 'ai-forge-devtools')}</span>
					</div>
					<div className="sanitizer-tester__panel-body">
						<textarea
							value={markdown}
							onChange={(e) => setMarkdown(e.target.value)}
							placeholder={__('Collez le markdown source ici...', 'ai-forge-devtools')}
							disabled={isLoading}
						/>
					</div>
				</div>

				<div className="sanitizer-tester__panel">
					<div className="sanitizer-tester__panel-header">
						<span className="dashicons dashicons-html"></span>
						<span>{__('Contenu Gutenberg', 'ai-forge-devtools')}</span>
					</div>
					<div className="sanitizer-tester__panel-body">
						<textarea
							value={content}
							onChange={(e) => setContent(e.target.value)}
							placeholder={__('Collez le contenu Gutenberg généré ici...', 'ai-forge-devtools')}
							disabled={isLoading}
						/>
					</div>
				</div>

				<div className="sanitizer-tester__panel sanitizer-tester__panel--optional">
					<div className="sanitizer-tester__panel-header">
						<span className="dashicons dashicons-layout"></span>
						<span>{__('Template (optionnel)', 'ai-forge-devtools')}</span>
						{loadingTemplate && <Spinner />}
					</div>
					<div className="sanitizer-tester__panel-body">
						{templates.length > 0 && (
							<SelectControl
								value={selectedTemplateId}
								options={[
									{ value: '', label: __('— Sélectionner un template —', 'ai-forge-devtools') },
									...templates.map((t) => ({
										value: String(t.id),
										label: t.title,
									})),
								]}
								onChange={handleTemplateSelect}
								disabled={isLoading || loadingTemplate}
								__nextHasNoMarginBottom
							/>
						)}
						<textarea
							value={template}
							onChange={(e) => {
								setTemplate(e.target.value);
								setSelectedTemplateId('');
							}}
							placeholder={__('Collez le template Gutenberg pour tester le shift down des titres...', 'ai-forge-devtools')}
							disabled={isLoading || loadingTemplate}
						/>
					</div>
				</div>
			</div>

			<div className="sanitizer-tester__actions">
				<Button
					variant="primary"
					onClick={handleSubmit}
					disabled={isLoading || !content.trim() || !markdown.trim()}
				>
					{isLoading ? (
						<>
							<Spinner />
							{__('Analyse en cours...', 'ai-forge-devtools')}
						</>
					) : (
						__('Tester la sanitization', 'ai-forge-devtools')
					)}
				</Button>
				<Button
					variant="secondary"
					onClick={handleClear}
					disabled={isLoading}
				>
					{__('Effacer', 'ai-forge-devtools')}
				</Button>
			</div>

			{result && <SanitizerResult result={result} />}
		</div>
	);
}

function SanitizerResult({ result }) {
	const { sanitized, changes, has_changes } = result;

	const formatChange = (change) => {
		if (change.type === 'heading_level') {
			return `Heading "${change.text}" : level ${change.from} → ${change.to}`;
		}
		if (change.type === 'list_type') {
			return `List : type ${change.from} → ${change.to}`;
		}
		if (change.type === 'list_items_wrapped') {
			return `List : ${change.count} items wrapped in wp:list-item`;
		}
		if (change.type === 'preamble_stripped') {
			return `Preamble stripped: "${change.content}${change.content.length >= 100 ? '...' : ''}"`;
		}
		if (change.type === 'postamble_stripped') {
			return `Postamble stripped: "${change.content}${change.content.length >= 100 ? '...' : ''}"`;
		}
		if (change.type === 'empty_paragraphs_removed') {
			return `${change.count} empty paragraph${change.count > 1 ? 's' : ''} removed`;
		}
		if (change.type === 'lorem_ipsum_removed') {
			return `${change.count} lorem ipsum block${change.count > 1 ? 's' : ''} removed`;
		}
		if (change.type === 'quote_citation_synced') {
			return `Quote citation synced: "${change.citation}"`;
		}
		if (change.type === 'attribute_added') {
			return `${change.block} : ajout attribut "${change.attribute}"`;
		}
		if (change.type === 'heading_attrs_normalized') {
			return __('Heading : attributs et classes réordonnés', 'ai-forge-devtools');
		}
		if (change.type === 'cover_dim_class_added') {
			return __('Cover : classe has-background-dim ajoutée', 'ai-forge-devtools');
		}
		if (change.type === 'empty_containers_removed') {
			return `${change.count} ${change.count > 1 ? __('conteneurs vides supprimés', 'ai-forge-devtools') : __('conteneur vide supprimé', 'ai-forge-devtools')}`;
		}
		if (change.type === 'orphaned_tags_cleaned') {
			return `${change.count} ${change.count > 1 ? __('balises orphelines nettoyées', 'ai-forge-devtools') : __('balise orpheline nettoyée', 'ai-forge-devtools')}`;
		}
		return JSON.stringify(change);
	};

	return (
		<div className="sanitizer-tester__result">
			<div className="sanitizer-tester__panel sanitizer-tester__panel--result">
				<div className="sanitizer-tester__panel-header">
					<span className="dashicons dashicons-yes-alt"></span>
					<span>{__('Résultat', 'ai-forge-devtools')}</span>
					<CopyButton content={sanitized} />
				</div>
				<div className="sanitizer-tester__panel-body">
					{has_changes ? (
						<>
							<div className="sanitizer-tester__changes">
								<Notice status="warning" isDismissible={false}>
									{changes.length}{' '}
									{changes.length === 1
										? __('correction effectuée', 'ai-forge-devtools')
										: __('corrections effectuées', 'ai-forge-devtools')}
								</Notice>
								<ul className="sanitizer-tester__changes-list">
									{changes.map((change, index) => (
										<li key={index}>{formatChange(change)}</li>
									))}
								</ul>
							</div>
							<pre className="sanitizer-tester__code">
								<code>{sanitized}</code>
							</pre>
						</>
					) : (
						<Notice status="success" isDismissible={false}>
							{__('Aucune correction nécessaire - le contenu est valide.', 'ai-forge-devtools')}
						</Notice>
					)}
				</div>
			</div>
		</div>
	);
}

export default SanitizerTester;
