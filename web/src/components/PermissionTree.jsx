import { useMemo } from 'react';
import { Alert, Button, Card, Space, Tree, Typography } from 'antd';

/**
 * 只保留叶子权限 key
 * antd 勾选父节点时，返回的 checked 里会带上父级分组的 key，
 * 而父级只用于菜单分组、不是真正的权限点，入库前要过滤掉。
 */
function onlyLeafKeys(keys, allKeys) {
  const list = Array.isArray(keys) ? keys : keys?.checked || [];
  return list.map(String).filter((k) => allKeys.includes(k));
}

/**
 * 权限勾选树
 *
 * @param {Array}  tree     权限树，来自 GET /permission/tree
 * @param {Array}  allKeys  全部叶子权限 key
 * @param {Array}  value    已勾选的叶子 key
 * @param {Function} onChange 勾选变化回调，参数为叶子 key 数组
 * @param {boolean} disabled 是否禁用（超级管理员传 true，表示默认全权限）
 */
export default function PermissionTree({ tree = [], allKeys = [], value = [], onChange, disabled = false }) {
  const checkedKeys = useMemo(() => onlyLeafKeys(value, allKeys), [value, allKeys]);

  const handleCheck = (checked) => {
    if (disabled || !onChange) {
      return;
    }
    onChange(onlyLeafKeys(checked, allKeys));
  };

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <Typography.Text type="secondary">
          已选 {checkedKeys.length} / {allKeys.length} 项
        </Typography.Text>
        <Space>
          <Button size="small" onClick={() => handleCheck(allKeys)} disabled={disabled}>
            全选
          </Button>
          <Button size="small" onClick={() => handleCheck([])} disabled={disabled}>
            清空
          </Button>
        </Space>
      </div>

      {disabled && (
        <Alert
          type="info"
          showIcon
          message="超级管理员默认拥有全部权限，无需单独分配"
          style={{ marginBottom: 12 }}
        />
      )}

      <Card size="small" bordered style={{ maxHeight: 340, overflow: 'auto', background: '#fafafa' }}>
        <Tree
          checkable
          selectable={false}
          disabled={disabled}
          defaultExpandAll
          treeData={tree}
          checkedKeys={checkedKeys}
          onCheck={handleCheck}
        />
      </Card>
    </div>
  );
}
