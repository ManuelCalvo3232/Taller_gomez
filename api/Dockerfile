# API Node.js
FROM node:22-alpine

WORKDIR /app

# Primero las dependencias: así Docker reutiliza esta capa si el código cambia y no las dependencias
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY src/ ./src/

ENV PORT=3000
EXPOSE 3000

CMD ["node", "src/server.js"]