FROM node:20-alpine

RUN apk add --no-cache git python3 make g++

WORKDIR /app

COPY package*.json ./
RUN npm install --omit=dev 2>/dev/null || true

COPY . .

EXPOSE 3000

CMD ["node", "index.js"]
