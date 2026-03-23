import { AuditLogService } from './audit-log.service';
import { mkdtempSync, readFileSync, rmSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';

describe('AuditLogService', () => {
  let tempDir: string;
  let service: AuditLogService;

  beforeEach(() => {
    tempDir = mkdtempSync(join(tmpdir(), 'audit-log-test-'));
    process.env.AUDIT_LOG_DIR = tempDir;
    service = new AuditLogService();
  });

  afterEach(() => {
    delete process.env.AUDIT_LOG_DIR;
    rmSync(tempDir, { recursive: true, force: true });
  });

  it('writes request entries into users-api.log', async () => {
    await service.logRequest({
      ts: '2026-03-22T12:00:00.000Z',
      requestId: 'req-1',
      route: '/external/user',
      statusCode: 200,
    });

    const content = readFileSync(join(tempDir, 'users-api.log'), 'utf8').trim();
    const parsed = JSON.parse(content);

    expect(parsed.requestId).toBe('req-1');
    expect(parsed.statusCode).toBe(200);
  });

  it('writes error entries into users-error.log', async () => {
    await service.logError({
      ts: '2026-03-22T12:00:00.000Z',
      requestId: 'req-2',
      route: '/external/user',
      statusCode: 500,
      message: 'Server error',
    });

    const content = readFileSync(join(tempDir, 'users-error.log'), 'utf8').trim();
    const parsed = JSON.parse(content);

    expect(parsed.requestId).toBe('req-2');
    expect(parsed.statusCode).toBe(500);
    expect(parsed.message).toBe('Server error');
  });
});
