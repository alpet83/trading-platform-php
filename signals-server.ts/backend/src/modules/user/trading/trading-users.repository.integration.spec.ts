/**
 * Integration test — requires a live MySQL connection.
 *
 * Run explicitly:
 *   TRADING_DB_AUTH_URL=mysql://user:pass@host/db npx jest trading-users.repository.integration --runInBand
 *
 * Skipped automatically when TRADING_DB_AUTH_URL is not set.
 */
import { createConnection } from 'mysql2/promise';
import { TradingUsersRepository } from './trading-users.repository';
import { TradingDbConnection, TradingDbConnectionFactory } from './trading-users.types';
import { sanitizeDbUrl } from '../../../config/env.validation';

const DB_URL = process.env.TRADING_DB_AUTH_URL;
const describeOrSkip = DB_URL ? describe : describe.skip;

describeOrSkip('TradingUsersRepository (integration)', () => {
  let repository: TradingUsersRepository;

  beforeAll(() => {
    const url = sanitizeDbUrl(DB_URL!);
    console.log(`[integration] connecting to: ${url.replace(/:([^:@]+)@/, ':***@')}`);
    /** Factory opens a fresh connection per call — same as production module. */
    const factory: TradingDbConnectionFactory = async () =>
      createConnection(url) as unknown as TradingDbConnection;
    repository = new TradingUsersRepository(factory);
  });

  it('connects and reads chat_users table', async () => {
    const users = await repository.findAll();

    expect(Array.isArray(users)).toBe(true);
    console.log(`chat_users row count: ${users.length}`);

    for (const user of users) {
      expect(typeof user.id).toBe('number');
      expect(typeof user.user_name).toBe('string');
      expect(Array.isArray(user.rights)).toBe(true);
      expect(user.enabled === 0 || user.enabled === 1).toBe(true);
    }
  });

  it('countAll returns a non-negative integer', async () => {
    const count = await repository.countAll();

    expect(typeof count).toBe('number');
    expect(count).toBeGreaterThanOrEqual(0);
    console.log(`countAll: ${count}`);
  });

  it('getAdminUsers returns only rows with admin right and enabled=1', async () => {
    const admins = await repository.getAdminUsers();

    expect(Array.isArray(admins)).toBe(true);

    for (const admin of admins) {
      expect(admin.rights).toContain('admin');
      expect(admin.enabled).toBe(1);
    }

    console.log(`admin users: ${admins.map((a) => a.user_name).join(', ') || '(none)'}`);
  });
});
