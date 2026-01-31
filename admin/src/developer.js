import { createRoot } from '@wordpress/element';
import DeveloperPage from './pages/DeveloperPage';
import './styles/developer-page.scss';

document.addEventListener('DOMContentLoaded', () => {
	const container = document.getElementById('aiforge-dev-root');
	if (container) {
		const root = createRoot(container);
		root.render(<DeveloperPage />);
	}
});
