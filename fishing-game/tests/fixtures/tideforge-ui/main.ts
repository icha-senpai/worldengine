import '@fontsource/silkscreen/latin-400.css';
import '@fontsource/silkscreen/latin-700.css';
import '@fontsource/nunito/latin-400.css';
import '@fontsource/nunito/latin-700.css';
import '../../../apps/web/src/lib/game/theme.css';
import { mount } from 'svelte';
import Dashboard from '../../../apps/web/src/lib/game/Dashboard.svelte';

mount(Dashboard, { target: document.getElementById('app')! });
