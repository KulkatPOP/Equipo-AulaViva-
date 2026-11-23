import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const client = dirname(fileURLToPath(import.meta.url));
const root = resolve(client, '../..');
const version = JSON.parse(readFileSync(join(root, 'openapitools.json'), 'utf8'))['generator-cli'].version;
assert.equal(version, '7.25.0', 'Revisar la adaptación antes de cambiar el generador.');
const jar = process.env.OPENAPI_GENERATOR_JAR;
const launcher = join(root, 'node_modules/@openapitools/openapi-generator-cli/main.js');
assert(jar ? existsSync(jar) : existsSync(launcher),
    'Ejecuta npm ci o define OPENAPI_GENERATOR_JAR con el JAR 7.25.0.');
const executable = jar ? 'java' : process.execPath;
const prefix = jar ? ['-jar', jar] : [launcher];
const run = (...args) => execFileSync(executable, [...prefix, ...args], { cwd: root, encoding: 'utf8' });
assert(run('version').trim().split(/\r?\n/).includes(version), 'Versión real del generador incorrecta.');

// No alterar silenciosamente el mapeo cuando cambie el contrato.
const spec = readFileSync(join(root, 'api/openapi.yaml'), 'utf8').replace(/\r\n/g, '\n');
const operation = spec.split('  /invitaciones:\n')[1]?.split('\ncomponents:')[0];
assert(operation, 'No se encontró la operación de invitaciones.');
assert.match(operation, /'201':[\s\S]*?schema:\s*\n\s*\$ref: '#\/components\/schemas\/InvitacionResponse'/);
assert.match(operation, /'200':[\s\S]*?schema:\s*\n\s*\$ref: '#\/components\/schemas\/InvitacionReplayResponse'/);

function replaceOnce(text, before, after) {
    assert.equal(text.split(before).length, 2, `Salida del generador inesperada: ${before}`);
    return text.replace(before, after);
}

const temporary = mkdtempSync(join(tmpdir(), 'aulaviva-api-client-'));
try {
    run('generate', '-i', 'api/openapi.yaml', '-g', 'typescript-fetch', '-o', temporary,
        '--additional-properties=hideGenerationTimestamp=true');
    const apiFile = join(temporary, 'apis/InvitacionesApi.ts');
    let api = readFileSync(apiFile, 'utf8').replace(/\r\n/g, '\n');
    api = replaceOnce(api, "import * as runtime from '../runtime';",
        "import * as runtime from '../runtime';\n" +
        "import { InvitacionFromJSON } from '../models/Invitacion';\n" +
        "import { IdempotencyMetaFromJSON } from '../models/IdempotencyMeta';\n" +
        '// Adaptación reproducible 200/201: packages/api-client/generate.mjs.');
    api = replaceOnce(api, 'Promise<runtime.ApiResponse<InvitacionReplayResponse>>',
        'Promise<runtime.ApiResponse<InvitacionResponse | InvitacionReplayResponse>>');
    api = replaceOnce(api, 'Promise<InvitacionReplayResponse>',
        'Promise<InvitacionResponse | InvitacionReplayResponse>');
    api = replaceOnce(api,
        '        return new runtime.JSONApiResponse(response, (jsonValue) => InvitacionReplayResponseFromJSON(jsonValue));',
        `        if (response.status === 201) {
            return new runtime.JSONApiResponse<InvitacionResponse>(response, (jsonValue) => ({
                data: InvitacionFromJSON(jsonValue.data),
                meta: IdempotencyMetaFromJSON(jsonValue.meta),
            }));
        }
        if (response.status === 200) {
            return new runtime.JSONApiResponse<InvitacionReplayResponse>(response, (jsonValue) => InvitacionReplayResponseFromJSON(jsonValue));
        }
        throw new runtime.ResponseError(response, 'Estado de éxito no documentado para invitaciones.');`);
    writeFileSync(apiFile, api.split('\n').map(line => line.replace(/[\t ]+$/, '')).join('\n'));

    const docFile = join(temporary, 'docs/InvitacionesApi.md');
    let doc = readFileSync(docFile, 'utf8');
    doc = replaceOnce(doc, '> InvitacionReplayResponse crearInvitacion(',
        '> InvitacionResponse | InvitacionReplayResponse crearInvitacion(');
    doc = replaceOnce(doc, '[**InvitacionReplayResponse**](InvitacionReplayResponse.md)',
        '[**InvitacionResponse**](InvitacionResponse.md) (HTTP 201) | ' +
        '[**InvitacionReplayResponse**](InvitacionReplayResponse.md) (HTTP 200)');
    writeFileSync(docFile, doc);

    // Publicar únicamente archivos generados ya versionados. No copiar scaffolding,
    // binarios, ni sobrescribir esta customización o sus pruebas.
    const files = execFileSync('git', ['ls-files', '-z', '--', 'packages/api-client'],
        { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
    const updates = [];
    for (const name of files) {
        const target = join(root, name);
        const path = relative(client, target);
        if (path.startsWith('.openapi-generator')) continue;
        const source = join(temporary, path);
        if (!existsSync(source)) continue;
        const original = readFileSync(target, 'utf8').replace(/\r\n/g, '\n');
        const generated = readFileSync(source, 'utf8').replace(/\r\n/g, '\n');
        const trim = line => line.replace(/[\t ]+$/, '');
        if (path.replace(/\\/g, '/') === 'apis/InvitacionesApi.ts') {
            if (original !== generated) updates.push([target, generated]);
            continue;
        }
        if (original.split('\n').map(trim).join('\n') === generated.split('\n').map(trim).join('\n')) continue;
        // Conservar whitespace existente en líneas iguales y no añadir espacios finales.
        const existing = new Map(original.split('\n').map(line => [trim(line), line]));
        updates.push([target, generated.split('\n').map(line => existing.get(trim(line)) ?? trim(line)).join('\n')]);
    }
    for (const [target, contents] of updates) writeFileSync(target, contents, 'utf8');
    console.log(`Cliente generado con ${version}; adaptación 200/201 aplicada; ${updates.length} archivos actualizados.`);
} finally {
    rmSync(temporary, { recursive: true, force: true });
}
