import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  // Тот же алиас, что и в tsconfig: без него тесты видят только импорты типов
  // (они стираются при сборке), а обычный импорт из '@/lib/...' не находится.
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
