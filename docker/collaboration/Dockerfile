# Self-hosted Hocuspocus server for Vale editor collaboration.
# Build: docker build -t vale-collaboration docker/collaboration
# Run:   docker run --rm -p 1234:1234 -e COLLAB_SECRET=dev-secret vale-collaboration
FROM node:26-alpine

WORKDIR /app
ENV NODE_ENV=production

COPY package.json ./
RUN npm install --omit=dev && npm cache clean --force

COPY server.mjs ./

USER node
EXPOSE 1234

CMD ["node", "server.mjs"]
