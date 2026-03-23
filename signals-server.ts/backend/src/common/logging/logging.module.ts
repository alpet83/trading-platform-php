import { Module } from '@nestjs/common';
import { AuditLogService } from './audit-log.service';
import { LoggerMiddleware } from '@common/middleware/logger.middleware';
import { RequestIdMiddleware } from '@common/middleware/request-id.middleware';

@Module({
  providers: [AuditLogService, LoggerMiddleware, RequestIdMiddleware],
  exports: [AuditLogService, LoggerMiddleware, RequestIdMiddleware],
})
export class LoggingModule {}
