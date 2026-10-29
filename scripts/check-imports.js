const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..', 'web', 'src');
const files = [];

(function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full);
    } else if (/\.jsx?$/.test(entry.name)) {
      files.push(full);
    }
  }
})(root);

let problems = 0;

for (const file of files) {
  const code = fs.readFileSync(file, 'utf8');
  const imported = new Set();

  // 收集具名导入（antd / @ant-design/icons / 其它）
  const importRe = /import\s+(?:type\s+)?\{([^}]*)\}\s+from\s+['"][^'"]+['"]/g;
  let m;
  while ((m = importRe.exec(code))) {
    for (const raw of m[1].split(',')) {
      const name = raw.trim().split(/\s+as\s+/)[0].trim();
      if (name) {
        imported.add(name);
      }
    }
  }

  // 默认导入（如 import bgImage from '...'）
  const defaultRe = /import\s+([A-Za-z_$][\w$]*)\s+from\s+['"][^'"]+['"]/g;
  while ((m = defaultRe.exec(code))) {
    imported.add(m[1]);
  }

  // React 导入时，JSX 里的 React.xxx 也算
  const used = new Map();
  const tagRe = /<\/?([A-Za-z][\w.]*)/g;
  while ((m = tagRe.exec(code))) {
    const base = m[1].split('.')[0];
    // 只关心大写开头的组件
    if (/^[A-Z]/.test(base)) {
      used.set(base, (used.get(base) || 0) + 1);
    }
  }

  for (const [name] of used) {
    if (!imported.has(name)) {
      console.log(`[MISSING] ${path.relative(root, file)} : <${name}>`);
      problems++;
    }
  }
}

console.log(problems === 0 ? 'IMPORT CHECK OK: 所有 JSX 组件均已导入' : `共 ${problems} 处未导入`);
