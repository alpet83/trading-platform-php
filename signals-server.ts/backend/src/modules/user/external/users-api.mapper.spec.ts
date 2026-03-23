import {
  buildActionResult,
  toUserRecord,
  toUserRecords,
} from './users-api.mapper';

describe('users-api.mapper', () => {
  it('maps a trading user to external API shape', () => {
    expect(
      toUserRecord({
        id: 5,
        user_name: 'alice',
        rights: ['view', 'admin'],
        enabled: 1,
      }),
    ).toEqual({
      id: 5,
      user_name: 'alice',
      rights: ['view', 'admin'],
      enabled: 1,
    });
  });

  it('maps trading users list to external API list', () => {
    expect(
      toUserRecords([
        {
          id: 5,
          user_name: 'alice',
          rights: ['view', 'admin'],
          enabled: 1,
        },
        {
          id: 7,
          user_name: 'bob',
          rights: ['trade'],
          enabled: 0,
        },
      ]),
    ).toEqual([
      {
        id: 5,
        user_name: 'alice',
        rights: ['view', 'admin'],
        enabled: 1,
      },
      {
        id: 7,
        user_name: 'bob',
        rights: ['trade'],
        enabled: 0,
      },
    ]);
  });

  it('builds stable mutation result envelopes', () => {
    expect(
      buildActionResult('created', 11, {
        id: 11,
        user_name: 'charlie',
        rights: ['view'],
        enabled: 1,
      }),
    ).toEqual({
      ok: true,
      reason: 'created',
      id: 11,
      user: {
        id: 11,
        user_name: 'charlie',
        rights: ['view'],
        enabled: 1,
      },
    });

    expect(buildActionResult('not_found', 12, null)).toEqual({
      ok: false,
      reason: 'not_found',
      id: 12,
      user: null,
    });
  });
});
