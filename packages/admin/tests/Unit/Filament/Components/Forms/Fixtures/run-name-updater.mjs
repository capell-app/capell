import { readFileSync } from 'node:fs';

const { scripts, changeScript, typing, state, data } = JSON.parse(readFileSync(0, 'utf8'));
const get = (path) => path.split('.').reduce((value, key) => value?.[key], data);
const set = (path, value) => {
    const keys = path.split('.');
    const lastKey = keys.pop();
    const parent = keys.reduce((value, key) => (value[key] ??= {}), data);
    parent[lastKey] = value;
};

const execute = (script, value) => {
    new Function('$state', '$get', '$set', 'setTimeout', script)(value, get, set, (callback) => callback());
};

// Ordinary wire:model changes reactive state on each input event, even without
// a network request. Filament runs afterStateUpdatedJs through a $wire watcher.
const values = typing ? Array.from(state, (_, index) => state.slice(0, index + 1)) : [state];
for (const value of values) {
    data.name = value;
    for (const script of scripts) execute(script, value);
}

// Native change fires once when the completed input is committed or blurred.
// Filament's $set updates client state locally and does not call server hooks.
if (changeScript) execute(changeScript, state);

process.stdout.write(JSON.stringify(data));
