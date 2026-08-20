#!/usr/bin/env node
// SPDX-License-Identifier: MIT
import { runCli } from "./cli.js";

const io = {
  out: (text: string) => process.stdout.write(text + "\n"),
  err: (text: string) => process.stderr.write(text + "\n"),
};

const code = await runCli(process.argv.slice(2), io);
process.exit(code);
