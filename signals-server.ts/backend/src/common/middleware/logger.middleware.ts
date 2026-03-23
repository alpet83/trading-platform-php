import { Injectable, Logger, NestMiddleware } from '@nestjs/common';
import { Request, Response, NextFunction } from 'express';
import { AuditLogService } from '@common/logging/audit-log.service';

@Injectable()
export class LoggerMiddleware implements NestMiddleware {
  logger = new Logger('API');

  constructor(private readonly auditLogService: AuditLogService) {}

  use(req: Request, res: Response, next: NextFunction) {
    const { method, originalUrl, body } = req;
    const start = Date.now();

    res.on('finish', () => {
      const { statusCode } = res;
      const duration = Date.now() - start;
      const requestId = String(res.locals.requestId || '-');

      this.logger.debug(
        `[${requestId}] [${method}] ${originalUrl} [${statusCode}] - ${duration}ms`,
      );

      const payload = {
        ts: new Date().toISOString(),
        requestId,
        method,
        route: originalUrl,
        statusCode,
        durationMs: duration,
        body: this.sanitizeBody(body),
      };

      void this.auditLogService.logRequest(payload);

      if (statusCode >= 500) {
        void this.auditLogService.logError({
          ...payload,
          level: 'error',
          message: 'Server error response',
        });
      }

      if (Object.keys(body || {}).length > 0) {
        console.log(JSON.stringify(body, null, 2));
      }
    });

    next();
  }

  private sanitizeBody(body: unknown): Record<string, unknown> | null {
    if (!body || typeof body !== 'object') {
      return null;
    }

    const result: Record<string, unknown> = {};

    Object.entries(body as Record<string, unknown>).forEach(([key, value]) => {
      if (/token|authorization|password/i.test(key)) {
        result[key] = '[redacted]';
      } else {
        result[key] = value;
      }
    });

    return result;
  }
}
