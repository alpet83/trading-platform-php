import { RequestIdMiddleware } from './request-id.middleware';

describe('RequestIdMiddleware', () => {
  const middleware = new RequestIdMiddleware();

  it('generates request id when header is missing', () => {
    const req = {
      headers: {},
    } as any;

    const res = {
      locals: {},
      setHeader: jest.fn(),
    } as any;

    const next = jest.fn();

    middleware.use(req, res, next);

    expect(next).toHaveBeenCalledTimes(1);
    expect(typeof res.locals.requestId).toBe('string');
    expect(res.locals.requestId.length).toBeGreaterThan(10);
    expect(res.setHeader).toHaveBeenCalledWith('X-Request-Id', res.locals.requestId);
  });

  it('reuses incoming x-request-id header', () => {
    const req = {
      headers: {
        'x-request-id': 'req-123',
      },
    } as any;

    const res = {
      locals: {},
      setHeader: jest.fn(),
    } as any;

    const next = jest.fn();

    middleware.use(req, res, next);

    expect(next).toHaveBeenCalledTimes(1);
    expect(res.locals.requestId).toBe('req-123');
    expect(res.setHeader).toHaveBeenCalledWith('X-Request-Id', 'req-123');
  });
});
