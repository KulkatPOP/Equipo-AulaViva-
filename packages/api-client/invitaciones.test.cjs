const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { mkdtempSync, rmSync, writeFileSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { test, after } = require('node:test');

const root = path.resolve(__dirname, '../..');
const output = mkdtempSync(path.join(tmpdir(), 'aulaviva-sdk-test-'));
after(() => rmSync(output, { recursive: true, force: true }));
const tsc = process.env.TYPESCRIPT_TSC || path.join(root, 'node_modules/typescript/bin/tsc');
execFileSync(process.execPath, [tsc, '--target', 'ES2020', '--module', 'commonjs',
    '--lib', 'ES2020,DOM', '--strict', '--outDir', output, path.join(__dirname, 'index.ts')],
{ cwd: root, stdio: 'pipe' });
const sdk = require(path.join(output, 'index.js'));
const invitations = require(path.join(output, 'models/Invitacion.js'));
const replays = require(path.join(output, 'models/InvitacionReplayResponse.js'));
const originalInvitation = invitations.InvitacionFromJSON;
const originalReplay = replays.InvitacionReplayResponseFromJSON;
const data = { id: 8, email: 'alumno@example.com', rut: '66666666-6',
    username: 'alumno-ejemplo', rol: 'alumno', expira_en: '2026-10-02 00:19:15+00' };
const request = { idempotencyKey: 'clave-ficticia', invitacionRequest: {
    email: data.email, rut: data.rut, username: data.username, rol: data.rol } };

test('contrato estático: retornos público y Raw exactos, sin any ni variantes perdidas', () => {
    const modulePath = name => JSON.stringify(path.join(__dirname, name).replace(/\\/g, '/'));
    const contract = path.join(output, 'public-contract.ts');
    writeFileSync(contract, `
import type { InvitacionesApi } from ${modulePath('apis/InvitacionesApi')};
import type { InvitacionResponse } from ${modulePath('models/InvitacionResponse')};
import type { InvitacionReplayResponse } from ${modulePath('models/InvitacionReplayResponse')};
import type { ApiResponse } from ${modulePath('runtime')};

type Equal<A, B> =
    (<T>() => T extends A ? 1 : 2) extends
    (<T>() => T extends B ? 1 : 2) ? true : false;
type IsAny<T> = 0 extends (1 & T) ? true : false;
type Assert<T extends true> = T;
type Result = InvitacionResponse | InvitacionReplayResponse;
type PublicReturn = ReturnType<InvitacionesApi['crearInvitacion']>;
type RawReturn = ReturnType<InvitacionesApi['crearInvitacionRaw']>;
type PublicExact = Assert<Equal<PublicReturn, Promise<Result>>>;
type RawExact = Assert<Equal<RawReturn, Promise<ApiResponse<Result>>>>;
type PublicNotAny = Assert<Equal<IsAny<Awaited<PublicReturn>>, false>>;
type RawNotAny = Assert<Equal<IsAny<Awaited<RawReturn>>, false>>;
type RawValueNotAny = Assert<Equal<
    IsAny<Awaited<ReturnType<Awaited<RawReturn>['value']>>>, false>>;
`, 'utf8');
    execFileSync(process.execPath, [tsc, '--noEmit', '--target', 'ES2020', '--module', 'commonjs',
        '--lib', 'ES2020,DOM', '--strict', contract], { cwd: root, stdio: 'pipe' });
});

function client(status, body) {
    let sent;
    const api = new sdk.InvitacionesApi(new sdk.Configuration({ credentials: 'include',
        basePath: 'https://aula.example.test/api/v1', fetchApi: async (url, init) => {
            sent = { url, init };
            return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
        } }));
    return { api, checkRequest() {
        assert.equal(sent.url, 'https://aula.example.test/api/v1/invitaciones');
        assert.equal(sent.init.credentials, 'include');
        assert.equal(sent.init.method, 'POST');
        assert.equal(sent.init.headers['Idempotency-Key'], request.idempotencyKey);
        assert.deepEqual(JSON.parse(sent.init.body), request.invitacionRequest);
    } };
}

test('201 usa InvitacionFromJSON y conserva urlActivacion y el envoltorio', async () => {
    let invitationCalls = 0, replayCalls = 0;
    invitations.InvitacionFromJSON = value => { invitationCalls++; return originalInvitation(value); };
    replays.InvitacionReplayResponseFromJSON = value => { replayCalls++; return originalReplay(value); };
    try {
        const link = '/activar_cuenta.php?token=TOKEN_FICTICIO_NO_VALIDO';
        const c = client(201, { data: { ...data, url_activacion: link }, meta: {} });
        const result = await c.api.crearInvitacion(request);
        assert.equal(result.data.urlActivacion, link);
        assert.equal(result.data.expiraEn, data.expira_en);
        assert.equal(invitationCalls, 1);
        assert.equal(replayCalls, 0);
        c.checkRequest();
    } finally {
        invitations.InvitacionFromJSON = originalInvitation;
        replays.InvitacionReplayResponseFromJSON = originalReplay;
    }
});

test('200 usa InvitacionReplayResponseFromJSON sin enlace de activación', async () => {
    let invitationCalls = 0, replayCalls = 0;
    invitations.InvitacionFromJSON = value => { invitationCalls++; return originalInvitation(value); };
    replays.InvitacionReplayResponseFromJSON = value => { replayCalls++; return originalReplay(value); };
    try {
        const c = client(200, { data, meta: { idempotent_replay: true } });
        const raw = await c.api.crearInvitacionRaw(request);
        assert.equal(raw.raw.status, 200);
        const result = await raw.value();
        assert.equal(result.meta.idempotentReplay, true);
        assert.equal(Object.hasOwn(result.data, 'urlActivacion'), false);
        assert.equal(invitationCalls, 0);
        assert.equal(replayCalls, 1);
        c.checkRequest();
    } finally {
        invitations.InvitacionFromJSON = originalInvitation;
        replays.InvitacionReplayResponseFromJSON = originalReplay;
    }
});

test('basePath relativo por defecto y rechazo de éxitos no documentados', async () => {
    assert.equal(sdk.BASE_PATH, '/api/v1');
    assert.equal(sdk.UsuarioSesionRolEnum.Apoderado, 'apoderado');
    await assert.rejects(client(202, { data, meta: {} }).api.crearInvitacion(request),
        error => error instanceof sdk.ResponseError && error.response.status === 202);
});
