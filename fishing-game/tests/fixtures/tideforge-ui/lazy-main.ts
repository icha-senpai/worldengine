import { mount } from 'svelte';
import LazyHost from './LazyHost.svelte';

mount(LazyHost, { target: document.getElementById('app')! });
