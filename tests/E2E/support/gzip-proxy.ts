import { createServer, request, type Server } from 'node:http';
import { gzipSync } from 'node:zlib';

/**
 * Proxy locale che comprime le risposte testuali come il server di produzione (8.4). `php -S` non
 * comprime nulla: senza questo proxy Lighthouse misurerebbe il JavaScript a dimensione piena.
 * Nelle pagine HTML l'origine del server viene sostituita con quella del proxy (gli asset hanno URL
 * assoluti e la CSP ammette solo 'self'). Solo per le misure: nessun effetto sull'applicazione.
 */
export async function startGzipProxy(target: URL, port: number): Promise<Server> {
    const proxyHost = `127.0.0.1:${port}`;
    const server = createServer((incoming, outgoing) => {
        const upstream = request(
            { host: target.hostname, port: target.port, path: incoming.url, method: incoming.method, headers: { ...incoming.headers, host: target.host, 'accept-encoding': 'identity' } },
            (response) => {
                const chunks: Buffer[] = [];
                response.on('data', (chunk: Buffer) => chunks.push(chunk));
                response.on('end', () => {
                    const type = String(response.headers['content-type'] ?? '');
                    let body = Buffer.concat(chunks);

                    if (type.includes('text/html')) {
                        body = Buffer.from(body.toString('utf8').replaceAll(target.host, proxyHost), 'utf8');
                    }

                    const compressible = /text|javascript|json|css|svg|xml/.test(type) && /gzip/.test(String(incoming.headers['accept-encoding'] ?? ''));
                    const headers = { ...response.headers };
                    const payload = compressible ? gzipSync(body) : body;

                    if (compressible) {
                        headers['content-encoding'] = 'gzip';
                        headers.vary = 'Accept-Encoding';
                    }

                    headers['content-length'] = String(payload.length);
                    outgoing.writeHead(response.statusCode ?? 502, headers);
                    outgoing.end(payload);
                });
            },
        );
        upstream.on('error', () => outgoing.destroy());
        incoming.pipe(upstream);
    });

    await new Promise<void>((resolve) => server.listen(port, '127.0.0.1', resolve));

    return server;
}
