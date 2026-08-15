import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

export const SMOKE_DIR = path.resolve(here, '..');
export const LAB_DIR = path.resolve(here, '../../../..');
export const HARNESS_PHP = 'wp-content/plugins/wp-ai-forge-devtools/smoke/php/harness.php';

export const BASE_URL = process.env.AIFORGE_SMOKE_URL || 'http://localhost:8888';
export const ADMIN_USER = process.env.AIFORGE_SMOKE_USER || 'admin';
export const ADMIN_PASSWORD = process.env.AIFORGE_SMOKE_PASSWORD || 'password';

// Audit grade: S13 reads these screenshots directly.
export const VIEWPORT = { width: 1680, height: 1050 };

export const TIMEOUTS = {
	action: 20000,
	navigation: 60000,
	editor: 60000,
	search: 45000,
	siteWait: 180000,
};

export const SEARCH_QUERY = 'photo lumineuse d’un intérieur chaleureux';
