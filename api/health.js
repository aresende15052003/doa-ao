module.exports = async function handler(req, res) {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.statusCode = 200;
  res.end(JSON.stringify({
    ok: true,
    amplopayConfigured: Boolean(process.env.AMPLOPAY_PUBLIC_KEY && process.env.AMPLOPAY_SECRET_KEY)
  }));
};
