import { __ } from '@wordpress/i18n';
import { Button, Dropdown } from '@wordpress/components';

const VERDICT_LABELS = {
	pass: __('Réussite', 'ai-forge-devtools'),
	warnings: __('Avertissements', 'ai-forge-devtools'),
	fail: __('Échec', 'ai-forge-devtools'),
};

const SUBSCORE_LABELS = {
	completion: __('Complétion', 'ai-forge-devtools'),
	signature: __('Signature', 'ai-forge-devtools'),
	coverage: __('Couverture', 'ai-forge-devtools'),
	volume: __('Volume', 'ai-forge-devtools'),
	leaks: __('Fuites', 'ai-forge-devtools'),
	dups: __('Doublons', 'ai-forge-devtools'),
};

const SUBSCORE_KEYS = ['completion', 'signature', 'coverage', 'volume', 'leaks', 'dups'];

function parseJSONSafely(value) {
	if (value === undefined || value === null) return null;
	if (typeof value === 'object') return value;
	if (typeof value !== 'string') return null;
	try {
		return JSON.parse(value);
	} catch (e) {
		return null;
	}
}

function extractQualityData(meta) {
	if (!meta || !meta.quality_gate_verdict) {
		return null;
	}

	const verdict = meta.quality_gate_verdict;
	const globalScore = parseInt(meta.quality_score_global, 10);

	const subscores = {};
	SUBSCORE_KEYS.forEach((key) => {
		const raw = meta[`quality_score_${key}`];
		if (raw !== undefined && raw !== null && raw !== '') {
			subscores[key] = parseInt(raw, 10);
		}
	});

	const parsed = parseJSONSafely(meta.quality_report);
	const findings = Array.isArray(parsed?.per_section_findings) ? parsed.per_section_findings : [];
	const blockingReasons = Array.isArray(parsed?.blocking_reasons) ? parsed.blocking_reasons : [];

	return { verdict, globalScore, subscores, findings, blockingReasons };
}

function scoreTone(score) {
	if (score >= 80) return 'good';
	if (score >= 60) return 'warn';
	return 'bad';
}

function QualityGatePopover({ data }) {
	const { verdict, globalScore, subscores, findings, blockingReasons } = data;
	const hasIssues = verdict !== 'pass';

	return (
		<div className="aiforge-qg-popover">
			<div className={`aiforge-qg-popover__header aiforge-qg-popover__header--${verdict}`}>
				<div className="aiforge-qg-popover__title">
					<span className="aiforge-qg-popover__verdict">{VERDICT_LABELS[verdict] || verdict}</span>
					<span className="aiforge-qg-popover__global-score">{globalScore}<span className="aiforge-qg-popover__score-max">/100</span></span>
				</div>
			</div>

			{hasIssues && (
				<div className="aiforge-qg-popover__section aiforge-qg-popover__section--reasons">
					<h4 className="aiforge-qg-popover__section-title">{__('Raisons du verdict', 'ai-forge-devtools')}</h4>
					{blockingReasons.length > 0 ? (
						<ul className="aiforge-qg-popover__reasons">
							{blockingReasons.map((reason, idx) => (
								<li key={idx}>{reason}</li>
							))}
						</ul>
					) : (
						<p className="aiforge-qg-popover__empty">
							{__('Aucune raison bloquante enregistrée.', 'ai-forge-devtools')}
						</p>
					)}
				</div>
			)}

			<div className="aiforge-qg-popover__subscores">
				{SUBSCORE_KEYS.map((key) => {
					if (subscores[key] === undefined) return null;
					const score = subscores[key];
					const tone = scoreTone(score);
					return (
						<div key={key} className="aiforge-qg-subscore">
							<div className="aiforge-qg-subscore__label">{SUBSCORE_LABELS[key]}</div>
							<div className="aiforge-qg-subscore__bar">
								<div
									className={`aiforge-qg-subscore__fill aiforge-qg-subscore__fill--${tone}`}
									style={{ width: `${Math.max(0, Math.min(100, score))}%` }}
								/>
							</div>
							<div className="aiforge-qg-subscore__value">{score}</div>
						</div>
					);
				})}
			</div>

			{findings.length > 0 && (
				<div className="aiforge-qg-popover__section">
					<h4 className="aiforge-qg-popover__section-title">{__('Détails par section', 'ai-forge-devtools')}</h4>
					<div className="aiforge-qg-popover__findings">
						{findings.map((f, idx) => {
							const missingEntries = Object.entries(f.missing || {}).filter(([, count]) => count > 0);
							return (
								<div key={idx} className="aiforge-qg-finding">
									<div className="aiforge-qg-finding__header">
										<span className="aiforge-qg-finding__index">#{f.section_index}</span>
										<span className="aiforge-qg-finding__role">{f.role}</span>
										<span className="aiforge-qg-finding__slot">{__('slot', 'ai-forge-devtools')} {f.slot_id}</span>
									</div>
									{missingEntries.length > 0 && (
										<div className="aiforge-qg-finding__missing">
											{missingEntries.map(([sig, count]) => (
												<span key={sig} className="aiforge-qg-finding__tag">
													{sig} <span className="aiforge-qg-finding__count">×{count}</span>
												</span>
											))}
										</div>
									)}
								</div>
							);
						})}
					</div>
				</div>
			)}
		</div>
	);
}

function findQualityMeta(task) {
	const direct = extractQualityData(task?.meta);
	if (direct) return direct;

	if (task?.children) {
		for (const child of task.children) {
			const childData = extractQualityData(child.meta);
			if (childData) return childData;
		}
	}

	return null;
}

export function QualityGateButton({ task }) {
	const data = findQualityMeta(task);
	if (!data) return null;

	return (
		<Dropdown
			popoverProps={{ placement: 'top-end' }}
			className={`aiforge-qg-dropdown aiforge-qg-dropdown--${data.verdict}`}
			contentClassName="aiforge-qg-dropdown__content"
			renderToggle={({ isOpen, onToggle }) => (
				<Button
					variant="secondary"
					onClick={onToggle}
					aria-expanded={isOpen}
					className={`aiforge-qg-button aiforge-qg-button--${data.verdict}`}
				>
					<span className="aiforge-qg-button__dot" />
					<span className="aiforge-qg-button__label">
						{__('Qualité', 'ai-forge-devtools')} : {VERDICT_LABELS[data.verdict] || data.verdict}
					</span>
					<span className="aiforge-qg-button__score">{data.globalScore}</span>
				</Button>
			)}
			renderContent={() => <QualityGatePopover data={data} />}
		/>
	);
}
