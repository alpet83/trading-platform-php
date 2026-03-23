import { Module } from '@nestjs/common';
import { AdminScene } from './admin.scene';
import { AdminService } from './admin.service';

@Module({
  imports: [],
  providers: [AdminScene, AdminService],
})
export class AdminModule {}
