import { useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api/client';
import CopyButton from './CopyButton';

function SanitizerTester() {
	const [markdown, setMarkdown] = useState('');
	const [content, setContent] = useState('');
	const [result, setResult] = useState(null);
	const [isLoading, setIsLoading] = useState(false);
	const [error, setError] = useState(null);

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
			const response = await api.testSanitizer(markdown, content);
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
