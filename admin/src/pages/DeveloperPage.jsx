import { __ } from '@wordpress/i18n';
import SanitizerTester from '../components/SanitizerTester';

function DeveloperPage() {
	return (
		<div className="aiforge-developer-page__content">
			<h1>{__('Outils de développement', 'ai-forge-devtools')}</h1>
			<SanitizerTester />
		</div>
	);
}

export default DeveloperPage;
