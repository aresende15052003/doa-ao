const crypto = require('crypto');

const PIX_ENDPOINT = 'https://app.amplopay.com/api/v1/gateway/pix/receive';
const ALLOWED_VALUES = new Set([30, 50, 75, 100, 150, 200, 300, 500, 1000]);

function json(res, status, body) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
  res.end(JSON.stringify(body));
}

function saoPauloTomorrow() {
  const d = new Date(Date.now() + 24 * 60 * 60 * 1000);
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/Sao_Paulo',
    year: 'numeric', month: '2-digit', day: '2-digit'
  }).formatToParts(d);
  const get = type => parts.find(p => p.type === type)?.value;
  return `${get('year')}-${get('month')}-${get('day')}`;
}

async function readBody(req) {
  if (req.body && typeof req.body === 'object') return req.body;
  if (typeof req.body === 'string') {
    try { return JSON.parse(req.body); } catch { return null; }
  }
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  if (!chunks.length) return {};
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { return null; }
}

module.exports = async function handler(req, res) {
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');
    return json(res, 405, { ok: false, message: 'Método não permitido.' });
  }

  const publicKey = process.env.AMPLOPAY_PUBLIC_KEY || '';
  const secretKey = process.env.AMPLOPAY_SECRET_KEY || '';

  if (!publicKey || !secretKey) {
    return json(res, 500, {
      ok: false,
      message: 'As credenciais da AmploPay não estão configuradas no Vercel.'
    });
  }

  const input = await readBody(req);
  if (!input) {
    return json(res, 400, { ok: false, message: 'JSON inválido.' });
  }

  const amount = Number(input.amount || 0);
  const name = String(input.name || '').trim();
  const email = String(input.email || '').trim();
  const phone = String(input.phone || '').trim();
  const documentNumber = String(input.document || '').trim();

  if (!ALLOWED_VALUES.has(amount)) {
    return json(res, 400, { ok: false, message: 'Valor de contribuição inválido.' });
  }
  if (name.length < 2) {
    return json(res, 400, { ok: false, message: 'Informe seu nome.' });
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return json(res, 400, { ok: false, message: 'Informe um e-mail válido.' });
  }

  const identifier = `doacao_${Date.now()}_${crypto.randomBytes(5).toString('hex')}`;
  const client = { name, email };
  if (phone) client.phone = phone;
  if (documentNumber) client.document = documentNumber;

  const payload = {
    identifier,
    amount,
    client,
    dueDate: saoPauloTomorrow(),
    metadata: {
      source: 'pagina-doacao-vercel',
      identifier
    }
  };

  let gatewayResponse;
  try {
    gatewayResponse = await fetch(PIX_ENDPOINT, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'x-public-key': publicKey,
        'x-secret-key': secretKey
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(25000)
    });
  } catch (err) {
    return json(res, 502, {
      ok: false,
      message: 'Não foi possível conectar à AmploPay.',
      details: err && err.message ? err.message : 'Falha de conexão'
    });
  }

  const raw = await gatewayResponse.text();
  let data = null;
  if (raw) {
    try { data = JSON.parse(raw); } catch { data = null; }
  }

  if (!data) {
    return json(res, 502, {
      ok: false,
      message: `A AmploPay retornou uma resposta vazia ou inválida (${gatewayResponse.status}).`
    });
  }

  if (!gatewayResponse.ok) {
    return json(res, gatewayResponse.status >= 400 && gatewayResponse.status < 500 ? 400 : 502, {
      ok: false,
      message: data.message || data.errorDescription || 'A AmploPay recusou a criação do PIX.',
      errorCode: data.errorCode || null,
      details: data.details || null
    });
  }

  const pix = data.pix && typeof data.pix === 'object' ? data.pix : {};
  const code = String(pix.code || '');
  const image = String(pix.image || '');
  const expiresAt = String(pix.expiresAt || '');

  if (!code) {
    return json(res, 502, {
      ok: false,
      message: 'A transação foi criada, mas a AmploPay não retornou o PIX Copia e Cola.',
      transactionId: data.transactionId || null
    });
  }

  return json(res, 200, {
    ok: true,
    transactionId: data.transactionId || null,
    status: data.status || null,
    transactionStatus: data.transactionStatus || null,
    code,
    image,
    expiresAt
  });
};
