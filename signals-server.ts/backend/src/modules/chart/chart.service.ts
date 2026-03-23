import { HttpException, HttpStatus, Injectable } from '@nestjs/common';
import { backendFetch } from '../../config/env.validation';

@Injectable()
export class ChartService {
  async getChartData(exchange: string, account_id: string) {
    try {
      const res = await backendFetch(`/chart?exchange=${exchange}&account_id=${account_id}`);
      if (!res.ok) {
        throw new HttpException('Failed to fetch chart data', res.status);
      }
      return await res.json();
    } catch (err) {
      if (err instanceof HttpException) throw err;
      throw new HttpException('Chart API request failed', HttpStatus.INTERNAL_SERVER_ERROR);
    }
  }
}
