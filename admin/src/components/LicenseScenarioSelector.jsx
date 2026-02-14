/**
 * License Scenario Selector Component
 *
 * Dropdown for selecting a mock license scenario in dev tools.
 *
 * @package AIForgeDevTools
 */

import { useState, useEffect } from '@wordpress/element';
import { SelectControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import api from '../api/client';

const SCENARIO_LABELS = {
	valid: __('Licence valide', 'ai-forge-devtools'),
	expired: __('Licence expirée', 'ai-forge-devtools'),
	invalid: __('Clé invalide', 'ai-forge-devtools'),
	update_available: __('Mise à jour disponible', 'ai-forge-devtools'),
};

export default function LicenseScenarioSelector() {
	const [scenario, setScenario] = useState('valid');
	const [pending, setPending] = useState('valid');
	const [isLoading, setIsLoading] = useState(true);
	const [isSaving, setIsSaving] = useState(false);

	useEffect(() => {
		const fetch = async () => {
			try {
				const response = await api.getLicenseScenario();
				setScenario(response.data.scenario);
				setPending(response.data.scenario);
			} catch (error) {
				console.error('Failed to load license scenario:', error);
			} finally {
				setIsLoading(false);
			}
		};
		fetch();
	}, []);

	const handleApply = async () => {
		setIsSaving(true);
		try {
			const response = await api.setLicenseScenario(pending);
			setScenario(response.data.scenario);
		} catch (error) {
			console.error('Failed to set license scenario:', error);
		} finally {
			setIsSaving(false);
		}
	};

	const options = Object.entries(SCENARIO_LABELS).map(([value, label]) => ({
		value,
		label,
	}));

	return (
		<section className="aiforge-dev-section">
			<div className="aiforge-dev-section__header">
				<span className="dashicons dashicons-shield" />
				<div>
					<h2>{__('Scénario licence', 'ai-forge-devtools')}</h2>
					<p>{__('Simule les réponses du serveur de licence pour tester les différents états.', 'ai-forge-devtools')}</p>
				</div>
			</div>
			<div className="aiforge-dev-section__body">
				<div className="aiforge-dev-license-scenario__controls">
					<SelectControl
						value={pending}
						options={options}
						onChange={setPending}
						disabled={isLoading}
						__nextHasNoMarginBottom
					/>
					<Button
						variant="secondary"
						onClick={handleApply}
						isBusy={isSaving}
						disabled={isSaving || isLoading || pending === scenario}
					>
						{__('Appliquer', 'ai-forge-devtools')}
					</Button>
				</div>
				{!isLoading && pending !== scenario && (
					<p className="aiforge-dev-license-scenario__hint">
						{__('Scénario modifié, cliquez sur Appliquer pour confirmer.', 'ai-forge-devtools')}
					</p>
				)}
			</div>
		</section>
	);
}
