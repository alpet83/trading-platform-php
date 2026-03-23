import { Injectable, Logger } from '@nestjs/common';
import { promises as fs } from 'fs';
import { resolve } from 'path';

type JsonPrimitive = string | number | boolean | null;
type JsonValue = JsonPrimitive | JsonObject | JsonValue[];
type JsonObject = { [key: string]: JsonValue };

/** Loose payload type accepted by log methods — serialized to JSON as-is. */
type LogPayload = Record<string, unknown>;

@Injectable()
export class AuditLogService {
  private readonly logger = new Logger(AuditLogService.name);

  private readonly logsDir = resolve(
    process.env.AUDIT_LOG_DIR?.trim() || process.cwd(),
    process.env.AUDIT_LOG_DIR ? '' : 'logs',
  );

  private readonly apiLogFile = resolve(this.logsDir, 'users-api.log');

  private readonly errorLogFile = resolve(this.logsDir, 'users-error.log');

  async logRequest(payload: LogPayload): Promise<void> {
    await this.writeLine(this.apiLogFile, payload);
  }

  async logError(payload: LogPayload): Promise<void> {
    await this.writeLine(this.errorLogFile, payload);
  }

  private async writeLine(filePath: string, payload: LogPayload): Promise<void> {
    const line = `${JSON.stringify(payload)}\n`;

    try {
      await fs.mkdir(this.logsDir, { recursive: true });
      await fs.appendFile(filePath, line, 'utf8');
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      this.logger.error(`Failed to write audit log file: ${filePath} (${reason})`);
      this.logger.log(line.trim());
    }
  }
}
