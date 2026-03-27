import { IsBoolean, IsNumber, IsOptional, IsString } from 'class-validator';

export class UpdateInstancePositionCoefRequestDto {
  @IsString()
  bot: string;

  @IsNumber()
  position_coef: number;

  @IsOptional()
  @IsNumber()
  hostId?: number;
}

export class UpdateInstanceTradeEnabledRequestDto {
  @IsString()
  bot: string;

  @IsBoolean()
  trade_enabled: boolean;

  @IsOptional()
  @IsNumber()
  hostId?: number;
}

export class CancelInstanceOrderRequestDto {
  @IsString()
  bot: string;

  @IsString()
  order_id: string;

  @IsOptional()
  @IsNumber()
  hostId?: number;
}

export class UpdateInstanceOffsetRequestDto {
  @IsString()
  exchange: string;

  @IsString()
  account: string;

  @IsString()
  pair_id: string;

  @IsNumber()
  offset: number;

  @IsOptional()
  @IsNumber()
  hostId?: number;
}

export class SaveInstanceHostRequestDto {
  @IsString()
  host_name: string;

  @IsString()
  instance_url: string;

  @IsOptional()
  @IsBoolean()
  is_active?: boolean;
}
