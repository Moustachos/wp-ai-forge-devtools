import { __ } from '@wordpress/i18n';
import SanitizerTester from '../components/SanitizerTester';
import LicenseScenarioSelector from '../components/LicenseScenarioSelector';

function DeveloperPage() {
	return (
		<div className="aiforge-developer-page__content">
			<div className="aiforge-developer-page__header">
				<span className="dashicons dashicons-code-standards" />
				<div>
					<h1>{__('Outils de développement', 'ai-forge-devtools')}</h1>
					<p>{__('Simulez des scénarios et testez les composants internes d\'AI Forge.', 'ai-forge-devtools')}</p>
				</div>
			</div>

			<div className="aiforge-developer-page__sections">
				<LicenseScenarioSelector />
				<SanitizerTester />
			</div>
		</div>
	);
}

export default DeveloperPage;
