#!/usr/bin/env node
'use strict';

/**
 * Uploads an Android App Bundle to Google Play and assigns it to a track.
 *
 * This is intentionally dependency-free (only Node.js built-ins) because the
 * runner must work on a clean Windows machine with the Node.js already used by
 * Expo builds. The service account JSON is never printed or copied.
 */

const crypto = require('crypto');
const fs = require('fs');
const https = require('https');
const path = require('path');
const { URL } = require('url');

const API_BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3';
const UPLOAD_BASE = 'https://androidpublisher.googleapis.com/upload/androidpublisher/v3';
const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

function info(message) {
  console.log(`[google-play] ${message}`);
}

function fail(message, code = 1) {
  console.error(`[google-play] ERRO: ${message}`);
  process.exit(code);
}

function parseArgs(argv) {
  const result = { config: null, check: false };

  for (let index = 2; index < argv.length; index += 1) {
    const arg = argv[index];

    if (arg === '--config') {
      result.config = argv[++index];
    } else if (arg === '--check') {
      result.check = true;
    } else if (arg === '--help' || arg === '-h') {
      console.log('Uso: node google-play-submit.js --config <arquivo.json> [--check]');
      process.exit(0);
    } else {
      fail(`argumento desconhecido: ${arg}`);
    }
  }

  return result;
}

function readJsonFile(filePath, label) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    fail(`não foi possível ler ${label} (${filePath}): ${error.message}`);
  }
}

function base64url(value) {
  return Buffer.from(value)
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');
}

function signJwt(serviceAccount) {
  const now = Math.floor(Date.now() / 1000);
  const header = base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const payload = base64url(JSON.stringify({
    iss: serviceAccount.client_email,
    scope: SCOPE,
    aud: serviceAccount.token_uri || 'https://oauth2.googleapis.com/token',
    iat: now,
    exp: now + 3600,
  }));
  const signingInput = `${header}.${payload}`;
  const signer = crypto.createSign('RSA-SHA256');
  signer.update(signingInput);
  const signature = signer.sign(serviceAccount.private_key);

  return `${signingInput}.${base64url(signature)}`;
}

function parseResponseBody(text) {
  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function googleErrorMessage(status, body) {
  if (body && typeof body === 'object' && body.error) {
    const details = Array.isArray(body.error.errors)
      ? body.error.errors.map((item) => item.message).filter(Boolean).join('; ')
      : '';
    return `HTTP ${status}: ${body.error.message || 'erro da Google Play API'}${details ? ` (${details})` : ''}`;
  }

  const text = typeof body === 'string' ? body : JSON.stringify(body);
  return `HTTP ${status}: ${String(text).slice(0, 800)}`;
}

function requestJson(method, url, accessToken, body) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const payload = body === undefined ? null : Buffer.from(JSON.stringify(body));
    const headers = {
      Authorization: `Bearer ${accessToken}`,
      Accept: 'application/json',
    };

    if (payload) {
      headers['Content-Type'] = 'application/json';
      headers['Content-Length'] = payload.length;
    }

    const request = https.request({
      protocol: target.protocol,
      hostname: target.hostname,
      port: target.port || 443,
      path: target.pathname + target.search,
      method,
      headers,
    }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => {
        const parsed = parseResponseBody(Buffer.concat(chunks).toString('utf8'));
        const status = response.statusCode || 0;

        if (status >= 200 && status <= 299) {
          resolve(parsed);
          return;
        }

        reject(new Error(googleErrorMessage(status, parsed)));
      });
    });

    request.on('error', (error) => reject(new Error(`falha de rede: ${error.message}`)));
    if (payload) {
      request.write(payload);
    }
    request.end();
  });
}

function requestForm(url, fields) {
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const payload = Buffer.from(new URLSearchParams(fields).toString());
    const request = https.request({
      protocol: target.protocol,
      hostname: target.hostname,
      port: target.port || 443,
      path: target.pathname + target.search,
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': payload.length,
        Accept: 'application/json',
      },
    }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => {
        const parsed = parseResponseBody(Buffer.concat(chunks).toString('utf8'));
        const status = response.statusCode || 0;

        if (status >= 200 && status <= 299) {
          resolve(parsed);
          return;
        }

        reject(new Error(googleErrorMessage(status, parsed)));
      });
    });

    request.on('error', (error) => reject(new Error(`falha de rede ao autenticar: ${error.message}`)));
    request.write(payload);
    request.end();
  });
}

async function getAccessToken(serviceAccount) {
  const assertion = signJwt(serviceAccount);
  const token = await requestForm(
    serviceAccount.token_uri || 'https://oauth2.googleapis.com/token',
    {
      grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      assertion,
    },
  );

  if (!token || !token.access_token) {
    throw new Error('a Google OAuth API não retornou access_token');
  }

  return token.access_token;
}

function uploadBundle(url, accessToken, bundlePath) {
  return new Promise((resolve, reject) => {
    const stat = fs.statSync(bundlePath);
    const target = new URL(url);
    const request = https.request({
      protocol: target.protocol,
      hostname: target.hostname,
      port: target.port || 443,
      path: target.pathname + target.search,
      method: 'POST',
      headers: {
        Authorization: `Bearer ${accessToken}`,
        Accept: 'application/json',
        'Content-Type': 'application/octet-stream',
        'Content-Length': stat.size,
      },
    }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.on('end', () => {
        const parsed = parseResponseBody(Buffer.concat(chunks).toString('utf8'));
        const status = response.statusCode || 0;

        if (status >= 200 && status <= 299) {
          resolve(parsed);
          return;
        }

        reject(new Error(googleErrorMessage(status, parsed)));
      });
    });

    request.on('error', (error) => reject(new Error(`falha de rede no upload do AAB: ${error.message}`)));

    const stream = fs.createReadStream(bundlePath);
    stream.on('error', (error) => {
      request.destroy();
      reject(new Error(`falha ao ler o AAB: ${error.message}`));
    });
    stream.pipe(request);
  });
}

function validateServiceAccount(serviceAccount, filePath) {
  if (!serviceAccount || serviceAccount.type !== 'service_account') {
    fail(`o arquivo ${filePath} não é uma service account JSON (type=service_account).`);
  }

  if (!serviceAccount.client_email || !serviceAccount.private_key) {
    fail(`a service account em ${filePath} não contém client_email/private_key.`);
  }
}

function validateConfig(config, checkOnly) {
  if (!config || typeof config !== 'object') {
    fail('configuração inválida.');
  }

  if (!config.serviceAccount || !fs.existsSync(config.serviceAccount)) {
    fail(`service account não encontrada: ${config.serviceAccount || '(vazio)'}`);
  }

  if (!config.packageName || !/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/.test(config.packageName)) {
    fail(`package name inválido: ${config.packageName || '(vazio)'}`);
  }

  if (checkOnly) {
    return;
  }

  if (!config.track || !/^[a-z][a-z0-9_-]*$/.test(config.track)) {
    fail(`track inválida: ${config.track || '(vazio)'}`);
  }

  if (!config.aab || !fs.existsSync(config.aab)) {
    fail(`AAB não encontrado: ${config.aab || '(vazio)'}`);
  }

  if (path.extname(config.aab).toLowerCase() !== '.aab') {
    fail(`o arquivo de submit não é um .aab: ${config.aab}`);
  }

  if (config.releaseStatus && !['completed', 'draft'].includes(config.releaseStatus)) {
    fail(`releaseStatus inválido: ${config.releaseStatus} (use completed ou draft).`);
  }
}

async function runCheck(config, accessToken) {
  const encodedPackage = encodeURIComponent(config.packageName);
  const edit = await requestJson(
    'POST',
    `${API_BASE}/applications/${encodedPackage}/edits`,
    accessToken,
    {},
  );

  try {
    const tracks = await requestJson(
      'GET',
      `${API_BASE}/applications/${encodedPackage}/edits/${edit.id}/tracks`,
      accessToken,
    );
    const names = (tracks.tracks || []).map((track) => track.track).filter(Boolean);
    info(`credenciais OK para ${config.packageName}; tracks: ${names.join(', ') || '(nenhuma)'}`);
  } finally {
    await requestJson(
      'DELETE',
      `${API_BASE}/applications/${encodedPackage}/edits/${edit.id}`,
      accessToken,
    ).catch(() => {});
  }
}

async function runSubmit(config, accessToken) {
  const encodedPackage = encodeURIComponent(config.packageName);
  const encodedTrack = encodeURIComponent(config.track);
  const releaseStatus = config.releaseStatus || 'completed';

  info(`criando edição para ${config.packageName}...`);
  const edit = await requestJson(
    'POST',
    `${API_BASE}/applications/${encodedPackage}/edits`,
    accessToken,
    {},
  );

  try {
    info(`enviando AAB (${path.basename(config.aab)})...`);
    const bundle = await uploadBundle(
      `${UPLOAD_BASE}/applications/${encodedPackage}/edits/${edit.id}/bundles?uploadType=media`,
      accessToken,
      config.aab,
    );

    if (!bundle || !bundle.versionCode) {
      throw new Error('a Google Play API não retornou versionCode após o upload do AAB');
    }

    const versionCode = String(bundle.versionCode);
    info(`AAB enviado: versionCode=${versionCode}`);

    const release = {
      versionCodes: [versionCode],
      status: releaseStatus,
    };

    if (config.releaseName) {
      release.name = String(config.releaseName);
    }

    info(`associando versionCode ${versionCode} à track ${config.track} (status=${releaseStatus})...`);
    await requestJson(
      'PUT',
      `${API_BASE}/applications/${encodedPackage}/edits/${edit.id}/tracks/${encodedTrack}`,
      accessToken,
      { track: config.track, releases: [release] },
    );

    info('commitando a edição...');
    const commit = await requestJson(
      'POST',
      `${API_BASE}/applications/${encodedPackage}/edits/${edit.id}:commit`,
      accessToken,
      {},
    );

    if (commit && commit.changesNotSentForReview) {
      console.log('SUBMIT_WARNING=changes_not_sent_for_review');
      info('AVISO: o Google não enviou as alterações para revisão; verifique o Play Console.');
    }

    console.log(`SUBMIT_VERSION_CODE=${versionCode}`);
    console.log(`SUBMIT_TRACK=${config.track}`);
    console.log(`SUBMIT_STATUS=${releaseStatus}`);
    info(`submit concluído: ${config.packageName} → ${config.track} (versionCode ${versionCode})`);
  } catch (error) {
    await requestJson(
      'DELETE',
      `${API_BASE}/applications/${encodedPackage}/edits/${edit.id}`,
      accessToken,
    ).catch(() => {});
    throw error;
  }
}

async function main() {
  const args = parseArgs(process.argv);

  if (!args.config) {
    fail('informe --config <arquivo.json>');
  }

  const config = readJsonFile(args.config, 'configuração de submit');
  validateConfig(config, args.check);

  const serviceAccount = readJsonFile(config.serviceAccount, 'service account');
  validateServiceAccount(serviceAccount, config.serviceAccount);

  info(`autenticando como ${serviceAccount.client_email}...`);
  const accessToken = await getAccessToken(serviceAccount);

  if (args.check) {
    await runCheck(config, accessToken);
    return;
  }

  await runSubmit(config, accessToken);
}

main().catch((error) => {
  fail(error && error.message ? error.message : String(error));
});
