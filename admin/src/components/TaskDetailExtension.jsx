/**
 * Task Detail Modal Extension (DevTools)
 *
 * Adds Logs/Payloads/Metadata tabs and a Quality Gate badge to TaskDetailModal.
 * Only visible when dev mode is enabled.
 *
 * @package AIForgeDevTools
 */

import { __ } from '@wordpress/i18n';
import CopyButton from './CopyButton';
import { QualityGateButton } from './QualityGateButton';

/**
 * Get LLM child task for a markdown_to_gutenberg task
 */
function getLlmChildTask(task) {
	if (!task.children || task.children.length === 0) {
		return null;
	}
	return task.children.find((child) => child.task_type === 'llm_generate');
}

/**
 * Format date for logs
 */
function formatDate(dateString) {
	if (!dateString) return '-';
	const date = new Date(dateString);
	return date.toLocaleString('fr-FR', {
		year: 'numeric',
		month: 'long',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
	});
}

/**
 * Aggregate logs from parent task and all children, sorted by date
 */
function aggregateLogs(task) {
	const allLogs = [];

	// Add parent logs with task context
	if (task.logs && task.logs.length > 0) {
		task.logs.forEach((log) => {
			allLogs.push({
				...log,
				taskType: task.task_type,
				taskId: task.id,
			});
		});
	}

	// Add children logs with task context
	if (task.children && task.children.length > 0) {
		task.children.forEach((child) => {
			if (child.logs && child.logs.length > 0) {
				child.logs.forEach((log) => {
					allLogs.push({
						...log,
						taskType: child.task_type,
						taskId: child.id,
					});
				});
			}
		});
	}

	// Sort by created_at ascending
	allLogs.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

	return allLogs;
}

/**
 * Get task type short label for logs
 */
function getTaskTypeShortLabel(taskType) {
	const labels = {
		batch_markdown_to_gutenberg: 'Batch',
		markdown_to_gutenberg: 'M→G',
		llm_generate: 'LLM',
		wp_create_draft: 'Draft',
	};
	return labels[taskType] || taskType;
}

/**
 * Render Logs tab content
 */
function renderLogsTab(task) {
	const logs = aggregateLogs(task);

	if (logs.length === 0) {
		return (
			<div className="task-detail-modal__empty">
				<p>{__('Aucun log disponible', 'ai-forge-devtools')}</p>
			</div>
		);
	}

	return (
		<div className="task-detail-modal__logs">
			{logs.map((log, index) => (
				<div key={index} className={`task-log task-log--${log.level}`}>
					<span className="task-log__level">[{log.level}]</span>
					<span className="task-log__task-type">[{getTaskTypeShortLabel(log.taskType)}]</span>
					<span className="task-log__message">{log.message}</span>
					<span className="task-log__time">{formatDate(log.created_at)}</span>
				</div>
			))}
		</div>
	);
}

/**
 * Render Payloads tab content
 */
function renderPayloadsTab(task) {
	const llmTask = getLlmChildTask(task);
	const payloads = llmTask?.payloads || task.payloads || [];

	if (payloads.length === 0) {
		return (
			<div className="task-detail-modal__empty">
				<p>{__('Aucun payload disponible', 'ai-forge-devtools')}</p>
			</div>
		);
	}

	return (
		<div className="task-detail-modal__payloads">
			{payloads.map((payload, index) => (
				<div key={index} className="task-detail-modal__code-block">
					<div className="task-detail-modal__code-header">
						<strong>{payload.type}</strong>
						<CopyButton content={payload.payload} />
					</div>
					<pre className="task-detail-modal__code">{payload.payload}</pre>
				</div>
			))}
		</div>
	);
}

/**
 * Render Metadata tab content
 */
function renderMetadataTab(task) {
	const llmTask = getLlmChildTask(task);
	const meta = llmTask?.meta || task.meta || {};

	if (Object.keys(meta).length === 0) {
		return (
			<div className="task-detail-modal__empty">
				<p>{__('Aucune métadonnée disponible', 'ai-forge-devtools')}</p>
			</div>
		);
	}

	return (
		<div className="task-detail-modal__meta">
			<table className="task-meta-table">
				<tbody>
					{Object.entries(meta).map(([key, value]) => (
						<tr key={key}>
							<td className="task-meta-table__key">{key}</td>
							<td className="task-meta-table__value">
								{typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value)}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
}

/**
 * Add tabs to TaskDetailModal
 */
export function addTaskDetailTabs(tabs, context) {
	const isDevMode = window.aiforgeDevData?.isDevMode ?? false;
	if (!isDevMode) {
		return tabs;
	}

	const { task } = context;

	// Add Logs tab
	tabs.push({
		name: 'logs',
		title: __('Logs', 'ai-forge-devtools'),
		content: renderLogsTab(task),
	});

	// Add Payloads tab
	tabs.push({
		name: 'payloads',
		title: __('Payloads', 'ai-forge-devtools'),
		content: renderPayloadsTab(task),
	});

	// Add Metadata tab
	tabs.push({
		name: 'meta',
		title: __('Métadonnées', 'ai-forge-devtools'),
		content: renderMetadataTab(task),
	});

	return tabs;
}

/**
 * Add the Quality Gate badge to the TaskDetailModal footer.
 */
export function addTaskDetailFooterActions(actions, context) {
	const isDevMode = window.aiforgeDevData?.isDevMode ?? false;
	if (!isDevMode) {
		return actions;
	}

	const { task } = context;

	actions.unshift(
		<QualityGateButton key="quality-gate" task={task} />
	);

	return actions;
}
