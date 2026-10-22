// Development-only server. Run from any directory; no PHP or build step required.
import { createServer } from "node:http";
import { readFile, stat } from "node:fs/promises";
import { resolve, extname, sep } from "node:path";
import { fileURLToPath } from "node:url";
const root = fileURLToPath(new URL("../../public", import.meta.url));
const port = Number(process.env.PORT || 4173);
const origin = process.env.DORSIA_API_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw new Error(
    "DORSIA_API_ORIGIN must be a local http origin with an explicit port.",
  );
const types = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".svg": "image/svg+xml",
  ".jpg": "image/jpeg",
  ".png": "image/png",
};
const server = createServer(async (req, res) => {
  try {
    const pathname = new URL(req.url, "http://localhost").pathname;
    if (pathname.startsWith("/api/")) {
      if (!origin) {
        res.writeHead(503, { "Content-Type": "application/json" });
        res.end(
          JSON.stringify({
            error: {
              code: "service_unavailable",
              message:
                "The local API is not connected. Start the Laravel stack and set DORSIA_API_ORIGIN when running the preview server.",
            },
          }),
        );
        return;
      }
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const upstream = await fetch(origin + req.url, {
        method: req.method,
        headers: {
          Accept: "application/json",
          ...(req.headers.authorization
            ? { Authorization: req.headers.authorization }
            : {}),
          ...(req.headers["content-type"]
            ? { "Content-Type": req.headers["content-type"] }
            : {}),
          ...(req.headers["idempotency-key"]
            ? { "Idempotency-Key": req.headers["idempotency-key"] }
            : {}),
        },
        ...(chunks.length ? { body: Buffer.concat(chunks) } : {}),
        signal: AbortSignal.timeout(20000),
        redirect: "error",
      });
      res.writeHead(upstream.status, {
        "Content-Type":
          upstream.headers.get("content-type") || "application/json",
        "Cache-Control": "no-store",
      });
      res.end(Buffer.from(await upstream.arrayBuffer()));
      return;
    }
    let path = resolve(root, "." + decodeURIComponent(pathname));
    if (!path.startsWith(root + sep) && path !== root) {
      res.writeHead(403);
      res.end();
      return;
    }
    if ((await stat(path)).isDirectory()) {
      if (!pathname.endsWith("/")) {
        res.writeHead(302, { Location: pathname + "/" });
        res.end();
        return;
      }
      path = resolve(path, "index.html");
    }
    const data = await readFile(path);
    res.writeHead(200, {
      "Content-Type": types[extname(path)] || "application/octet-stream",
      "Cache-Control": "no-store",
      "X-Content-Type-Options": "nosniff",
      "Referrer-Policy": "no-referrer",
    });
    res.end(data);
  } catch (error) {
    if (req.url.startsWith("/api/")) {
      res.writeHead(502, { "Content-Type": "application/json" });
      res.end(
        JSON.stringify({
          error: {
            code: "service_unavailable",
            message:
              "The local API could not be reached. Retry the same request.",
          },
        }),
      );
      return;
    }
    res.writeHead(404, { "Content-Type": "text/plain" });
    res.end("Not found");
  }
});
server.listen(port, "127.0.0.1", () =>
  console.log(
    `Dorsia experience: http://127.0.0.1:${server.address().port}/experience/`,
  ),
);
