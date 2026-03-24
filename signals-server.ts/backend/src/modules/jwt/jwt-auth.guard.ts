import { Injectable, Logger, UnauthorizedException } from '@nestjs/common';
import { AuthGuard } from '@nestjs/passport';

@Injectable()
export class JwtAuthGuard extends AuthGuard('jwt') {
  private static readonly logger = new Logger(JwtAuthGuard.name);

  handleRequest(err: any, user: any, info: any) {
    if (err || !user) {
      const reason =
        info instanceof Error ? info.message : String(info ?? err ?? 'unknown');
      JwtAuthGuard.logger.warn(`JWT auth rejected: ${reason}`);
      throw err instanceof UnauthorizedException
        ? err
        : new UnauthorizedException(reason);
    }
    return user;
  }
}