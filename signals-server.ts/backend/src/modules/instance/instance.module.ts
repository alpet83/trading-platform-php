import { Module } from '@nestjs/common';
import { InstanceService } from '@modules/instance/instance.service';
import { InstanceController } from '@modules/instance/instance.controller';
import { InstanceHostsRepository } from '@modules/instance/instance-hosts.repository';

@Module({
  imports: [],
  controllers: [InstanceController],
  providers: [InstanceService, InstanceHostsRepository],
})
export class InstanceModule {}
