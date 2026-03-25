import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Post,
  Request,
  UseGuards,
} from '@nestjs/common';
import { UserExternalService } from '@modules/user/external/user.external.service';
import {
  CreateUserDTO,
  UpdateUserDTO,
} from '@modules/user/external/user.external.dto';
import { JwtAuthGuard } from '@modules/jwt/jwt-auth.guard';
import { AdminGuard } from '@common/auth/admin.guard';
@UseGuards(JwtAuthGuard)
@Controller()
export class UserExternalController {
  constructor(private service: UserExternalService) {}
  @Get('external/user')
  getUsers(@Request() req: { user: any }) {
    console.log(req.user);
    return this.service.getUsers(req.user);
  }
  @Get('isAdmin')
  isAdmin(@Request() req: { user: any }) {
    return this.service.isAdmin(req.user);
  }

  @UseGuards(JwtAuthGuard, AdminGuard)
  @Get('external/user/setup-bases')
  getSetupBaseGroups(@Request() req: { user: any }) {
    return this.service.getSetupBaseGroups(req.user);
  }
  @Post('external/user')
  createUser(@Body() body: CreateUserDTO, @Request() req: { user: any }) {
    return this.service.createUser(body, req.user);
  }

  @Post('external/user/update')
  updateUser(@Body() body: UpdateUserDTO, @Request() req: { user: any }) {
    return this.service.updateUser(req.user, body);
  }

  @Delete('external/user/:id')
  deleteUser(@Param('id') id: string | number, @Request() req: { user: any }) {
    return this.service.deleteUser(req.user, id);
  }
}
