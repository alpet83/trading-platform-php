import { CanActivate, ExecutionContext, Injectable } from '@nestjs/common';
import { UserExternalService } from '@modules/user/external/user.external.service';

@Injectable()
export class AdminGuard implements CanActivate {
  constructor(private readonly externalService: UserExternalService) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const request = context.switchToHttp().getRequest();
    const user = request.user;
    if (!user) return false;
    return Boolean(await this.externalService.isAdmin(user));
  }
}