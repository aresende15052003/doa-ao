module.exports = async function handler(req, res) {
  res.statusCode = 200;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end(JSON.stringify({
    ok: true,
    runtime: 'vercel-node',
    amplopayConfigured: Boolean(
      process.env.AMPLOPAY_PUBLIC_KEY &&
      process.env.AMPLOPAY_SECRET_KEY
    )
  }));
};
