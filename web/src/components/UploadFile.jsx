import { useState } from 'react';
import { Button, Image, Input, Space, Upload, message } from 'antd';
import { InboxOutlined } from '@ant-design/icons';
import { uploadApi } from '../api';
import { UPLOAD_LIMITS, UPLOAD_TYPE_TEXT, formatFileSize } from '../utils/constants';

const API_BY_TYPE = {
  image: uploadApi.image,
  video: uploadApi.video,
  file: uploadApi.file,
};

const ACCEPT = {
  image: '.jpg,.jpeg,.png,.gif,.webp,.bmp',
  video: '.mp4,.webm,.mov',
  file: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt',
};

/**
 * 校验文件大小，超限直接拦截并提示，不发起请求
 */
function checkSize(file, type) {
  const limit = UPLOAD_LIMITS[type] ?? UPLOAD_LIMITS.file;
  if (file.size > limit) {
    message.error(
      `${UPLOAD_TYPE_TEXT[type] || '文件'}大小不能超过 ${formatFileSize(limit)}，当前 ${formatFileSize(file.size)}`
    );
    return false;
  }
  return true;
}

/**
 * 单文件上传：点击选择后立即上传，成功后把附件记录回调出去
 */
export default function UploadFile({ type = 'image', folder = 'default', onSuccess, children }) {
  const [loading, setLoading] = useState(false);

  const handleUpload = async (file) => {
    const api = API_BY_TYPE[type] || uploadApi.file;
    const formData = new FormData();
    formData.append('file', file);
    if (folder) {
      formData.append('folder', folder);
    }

    setLoading(true);
    try {
      const res = await api(formData);
      message.success('上传成功');
      onSuccess?.(res.data);
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  };

  return (
    <Upload
      accept={ACCEPT[type]}
      showUploadList={false}
      beforeUpload={(file) => {
        if (!checkSize(file, type)) {
          return false;
        }
        handleUpload(file);
        return false; // 阻止 antd 自己的上传行为
      }}
    >
      {children || (
        <Button
          icon={<InboxOutlined />}
          loading={loading}
          title={`单个文件最大 ${formatFileSize(UPLOAD_LIMITS[type] ?? UPLOAD_LIMITS.file)}`}
        >
          上传{UPLOAD_TYPE_TEXT[type] || '文件'}
        </Button>
      )}
    </Upload>
  );
}

/**
 * 封面上传：带预览与手动输入地址
 */
export function CoverUpload({ value = '', onChange, folder = 'cover' }) {
  const [loading, setLoading] = useState(false);

  const handleUpload = async (file) => {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('folder', folder);
    setLoading(true);
    try {
      const res = await uploadApi.image(formData);
      onChange?.(res.data.url);
      message.success('封面上传成功');
    } catch (e) {
      // 错误提示已由拦截器统一处理
    } finally {
      setLoading(false);
    }
  };

  return (
    <Space direction="vertical" style={{ width: '100%' }} size={8}>
      <Space.Compact style={{ width: '100%' }}>
        <Input
          value={value}
          onChange={(e) => onChange?.(e.target.value)}
          placeholder="粘贴图片地址，或直接上传"
        />
        <Upload
          accept={ACCEPT.image}
          showUploadList={false}
          beforeUpload={(file) => {
            if (!checkSize(file, 'image')) {
              return false;
            }
            handleUpload(file);
            return false;
          }}
        >
          <Button loading={loading}>选择图片</Button>
        </Upload>
      </Space.Compact>
      {value ? (
        <Image
          src={value}
          alt="封面预览"
          height={120}
          style={{ objectFit: 'cover', borderRadius: 6 }}
          fallback=""
        />
      ) : (
        <div
          style={{
            height: 120,
            borderRadius: 6,
            border: '1px dashed #d9d9d9',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: '#bfbfbf',
            fontSize: 12,
          }}
        >
          未设置封面（最大 {formatFileSize(UPLOAD_LIMITS.image)}）
        </div>
      )}
    </Space>
  );
}
