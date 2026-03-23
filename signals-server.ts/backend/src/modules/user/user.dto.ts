import {
  IsBoolean,
  IsNumber,
  IsNumberString,
  IsOptional,
  IsString,
} from 'class-validator';
export class TelegramAuthDto {
  @IsNumber()
  id: string;

  @IsString()
  first_name: string;

  @IsString()
  @IsOptional()
  last_name?: string;

  @IsString()
  @IsOptional()
  username?: string;

  @IsString()
  hash: string;
}
export class UserCreateDto {
  @IsString()
  username: string;

  @IsString()
  password: string;

  @IsBoolean()
  isAdmin: boolean;
}

export class GetListRequestDto {
  @IsOptional()
  @IsNumberString()
  offset?: 0;

  @IsOptional()
  @IsNumberString()
  limit?: 20;
}

export class UserUpdateDto {
  @IsNumber()
  id: number;

  @IsString()
  username: string;

  @IsOptional()
  @IsString()
  password: string;
}
