// Cloudflare Worker - API 代理（解决 InfinityFree CORS 限制）
// 部署到 Cloudflare Workers 后，将 index.html 的 API_BASE 改为这个 Worker 地址

export default {
  async fetch(request) {
    const url = new URL(request.url);
    // 拼接 InfinityFree 的 api.php
    const target = 'https://even.infinityfree.me/api.php' + url.search;

    // 准备转发请求的 body
    let body = null;
    if (request.method === 'POST') {
      body = await request.text();
    }

    // 转发到 InfinityFree
    const resp = await fetch(target, {
      method: request.method,
      headers: { 'Content-Type': 'application/json' },
      body,
    });

    // 拿到响应，追加 CORS 头
    const data = await resp.text();
    return new Response(data, {
      status: resp.status,
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type',
      },
    });
  },
};
