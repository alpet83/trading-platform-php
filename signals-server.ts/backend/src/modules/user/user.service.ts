import { Injectable, Logger, UnauthorizedException } from '@nestjs/common';
import { EntityManager } from 'typeorm';
import { User } from './user.entity';
import { InjectEntityManager } from '@nestjs/typeorm';
import * as crypto from 'crypto';
import { JwtService } from '@nestjs/jwt';
import { TelegramAuthDto } from '@modules/user/user.dto';
import { Setup } from '@modules/signals/entity/setup.entity';
import { UserExternalService } from '@modules/user/external/user.external.service';
import { AdminNotifyService } from '@modules/events/admin-notify.service';
import { TradingUsersRepository } from '@modules/user/trading/trading-users.repository';

@Injectable()
export class UserService {
  private readonly logger = new Logger(UserService.name);

  constructor(
    @InjectEntityManager()
    private readonly man: EntityManager,
    private readonly jwtService: JwtService,
    private readonly externalService: UserExternalService,
    private readonly adminNotifyService: AdminNotifyService,
    private readonly tradingUsersRepository: TradingUsersRepository,
  ) {}

  validateTelegramAuth(data: TelegramAuthDto, botToken: string) {
    const { hash, ...userData } = data;
    this.logger.log("userData: " + JSON.stringify(userData));
    this.logger.log("token start: " + botToken.slice(0, 10));
    const sorted = Object.keys(userData)
      .sort()
      .map((key) => `${key}=${userData[key]}`)
      .join('\n');

    const secret = crypto.createHash('sha256').update(botToken).digest();
    const hmac = crypto
      .createHmac('sha256', secret)
      .update(sorted)
      .digest('hex');

    if (hmac !== hash) {
      this.logger.warn(
        `Ошибка авторизации Telegram: некорректные данные для пользователя id=${data.id}`,
      );
      throw new UnauthorizedException('Неверные данные Telegram');
    }
    return userData;
  }

  async loginWithTelegram(data: TelegramAuthDto, botToken: string) {
    if (typeof botToken === "undefined")  
       throw new UnauthorizedException("botToken not provided to backend. Process ENV: " + JSON.stringify(process.env) );
    const userData = this.validateTelegramAuth(data, botToken);

    // Проверяем пользователя в базе
    let user = await this.findByTelegramId(userData.id);
    if (!user) {
      this.logger.log(
        `Пользователь не найден в базе, создаем нового пользователя telegramId=${userData.id}`,
      );
      user = await this.createUser({
        telegramId: userData.id,
        firstName: userData.first_name,
        lastName: userData.last_name,
        username: userData.username,
      });
      this.logger.log(
        `Пользователь создан: id=${user.id}, telegramId=${user.telegramId}`,
      );
    }

    const payload = { id: user.id, username: user.username || user.firstName };
    const token = this.jwtService.sign(payload);

    await this.adminNotifyService.notifyLoginSuccess({
      actorTelegramId: user.telegramId,
      targetTelegramId: user.telegramId,
      userName: user.username || user.firstName,
      meta: {
        localUserId: user.id,
      },
    });

    return { token, user };
  }
  async findUser(id: number): Promise<User | null> {
    return await this.man.findOne(User, { where: { id } });
  }
  async findByTelegramId(telegramId: string): Promise<User | null> {
    return await this.man.findOne(User, { where: { telegramId } });
  }

  async createUser(data: {
    telegramId: string;
    firstName: string;
    lastName?: string;
    username?: string;
  }): Promise<User> {
    this.logger.log(
      `Создание нового пользователя с telegramId=${data.telegramId}`,
    );
    
    // Проверяем, является ли это первым пользователем в системе
    let isFirstUser = false;
    try {
      const totalUsers = await this.tradingUsersRepository.countAll();
      isFirstUser = totalUsers === 0;
      if (isFirstUser) {
        this.logger.log('Первый пользователь в системе - выдаем админские права');
      }
    } catch (error) {
      this.logger.warn(`Ошибка при проверке количества пользователей: ${error.message}`);
    }

    // Определяем права - админские для первого пользователя, иначе только просмотр
    const rights = isFirstUser ? ['admin'] : ['view'];

    const user = this.man.create(User, {
      telegramId: data.telegramId,
      firstName: data.firstName,
      lastName: data.lastName,
      username: data.username,
    });
    await this.externalService.createUser(
      {
        enabled: 1,
        rights,
        user_name: data.username,
        id: data.telegramId,
      },
      { telegramId: 1 },
    );
    const savedUser = await this.man.save(user);

    await this.adminNotifyService.notifyUserCreated({
      actorTelegramId: data.telegramId,
      targetTelegramId: data.telegramId,
      userName: data.username,
      rights,
      enabled: 1,
      meta: {
        source: 'user.service.createUser',
        localUserId: savedUser.id,
        isFirstUser,
      },
    });

    this.logger.log(
      `Пользователь успешно создан: id=${savedUser.id}, username=${savedUser.username}, rights=${rights.join(',')}`,
    );
    return savedUser;
  }

  async getUser(user: User) {
    if (user) {
      const userRes = user;
      const setups = await this.man
        .getRepository(Setup)
        .createQueryBuilder('setup')
        .leftJoin('setup.users', 'user')
        .where('user.telegramId = :telegramId', { telegramId: user.telegramId })
        .getMany();
      return {
        user: userRes,
        setups,
      };
    } else {
      return null;
    }
  }
}
