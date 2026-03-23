import {
  BadRequestException,
  InternalServerErrorException,
} from '@nestjs/common';

const BAD_REQUEST_PATTERNS = [
  'Invalid user id',
  'Invalid trading user rights payload',
  'Trading user rights must be an array',
  'Trading user enabled flag must be 0 or 1',
  'Trading user user_name must be a non-empty string',
  'Trading user id must be a positive integer',
  'Invalid trading user right:',
];

export const normalizeUsersApiError = (error: unknown): never => {
  if (error instanceof BadRequestException) {
    throw error;
  }

  const reason = error instanceof Error ? error.message : String(error);

  if (BAD_REQUEST_PATTERNS.some((pattern) => reason.includes(pattern))) {
    throw new BadRequestException(reason);
  }

  throw new InternalServerErrorException(reason);
};